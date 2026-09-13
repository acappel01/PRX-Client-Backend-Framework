<?php

namespace App\Services\Patient;

use App\Actions\Exceptions\ActionException;
use App\Enums\Integrations\IntegrationCapability;
use App\Integrations\Contracts\SendsTransactionalEmail;
use App\Integrations\IntegrationRegistry;
use App\Integrations\Messages\EmailMessage;
use App\Models\Integrations\IntegrationInstance;
use App\Workflows\Actions\CapabilityRouting;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use RuntimeException;
use Throwable;

/**
 * The system's own patient account mail: claim, create-account and reset links,
 * and the token-free "password changed" notice.
 *
 * One place answers "can this installation send a portal link at all", so the
 * three flows cannot drift into three different definitions of it. Every check
 * here describes the INSTALLATION — portal URL, the one transactional-email
 * integration, its test() — and never the address, so the 503 it produces
 * cannot be used to learn anything about a person.
 *
 * Why the system sends these rather than a workflow: they carry live
 * credentials. As a workflow step the token would sit in the workflow context,
 * the queued job and the run log, and could be routed to a webhook. Delivery
 * still goes through CapabilityRouting, so WHICH provider sends is the
 * operator's choice, exactly as for `send_email`.
 */
class PatientMail
{
    public function __construct(private readonly IntegrationRegistry $integrations) {}

    /**
     * Resolve the delivering integration, or refuse with a 503.
     *
     * @return array{0: IntegrationInstance, 1: SendsTransactionalEmail}
     *
     * @throws ActionException
     */
    public function deliveryOrFail(string $reason): array
    {
        if (blank(config('portal.url'))) {
            Log::error("{$reason} unavailable: PATIENT_PORTAL_URL is not set.");

            throw $this->unavailable();
        }

        try {
            $instance = CapabilityRouting::resolve(
                $this->integrations,
                IntegrationCapability::TransactionalEmail,
                null,
                'email',
            );

            $driver = $this->integrations->driverFor($instance);

            if (! $driver instanceof SendsTransactionalEmail) {
                throw new RuntimeException("[{$instance->slug}] cannot send transactional email.");
            }

            $driver->test($instance);
        } catch (Throwable $e) {
            // The routing and driver messages are written for an operator
            // ("configure one under Integrations"), not for a patient.
            Log::error("{$reason} unavailable: no usable transactional email integration.", [
                'reason' => $e->getMessage(),
            ]);

            throw $this->unavailable();
        }

        return [$instance, $driver];
    }

    /**
     * Refuse when a queued send would never be picked up.
     *
     * The anonymous link endpoints answer before sending (see
     * SendAccountLinkJob), so without this a stopped Horizon turns every
     * request into a cheerful 202 and no email. It describes the installation,
     * not the address. Only the redis connection Horizon supervises is checked.
     * `sync` (the test suite) runs the job inline; any other connection
     * (database, sqs) is NOT guarded here, and an install using one must
     * monitor its own workers.
     *
     * @throws ActionException
     */
    public function queueOrFail(string $reason): void
    {
        if (config('queue.default') !== 'redis') {
            return;
        }

        $running = rescue(
            fn (): bool => count(app(MasterSupervisorRepository::class)->all()) > 0,
            false,
            false,
        );

        if (! $running) {
            Log::error("{$reason} unavailable: no Horizon supervisor is running, so the email would never be sent.");

            throw $this->unavailable();
        }
    }

    /**
     * Send one message through a resolved integration. Exceptions propagate:
     * each caller decides what a failed send undoes.
     *
     * @param  array{0: IntegrationInstance, 1: SendsTransactionalEmail}  $delivery
     */
    public function send(array $delivery, EmailMessage $message): void
    {
        [$instance, $driver] = $delivery;

        $driver->sendEmail($instance, $message);
    }

    /** A portal URL for `portal.{key}` with `{token}` replaced. */
    public function portalLink(string $pathKey, ?string $plain = null): string
    {
        $base = rtrim((string) config('portal.url'), '/');
        $path = (string) config("portal.{$pathKey}");

        if ($plain !== null) {
            $path = str_replace('{token}', $plain, $path);
        }

        return $base.'/'.ltrim($path, '/');
    }

    public function unavailable(): ActionException
    {
        return ActionException::failed(
            'We cannot send email right now. Please try again later or contact support.',
            503,
        );
    }
}
