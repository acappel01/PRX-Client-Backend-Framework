<?php

namespace App\Jobs\Patient;

use App\Integrations\Messages\EmailMessage;
use App\Models\Patient;
use App\Services\Patient\PatientMail;
use App\Settings\BrandSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Two-step verification was turned on / off / reset / new recovery codes" — to
 * the account's own address.
 *
 * The alarm for a change the owner did not make. Same rules as
 * SendPasswordChangedNoticeJob: no token, nothing anyone typed, a link to the
 * forgot-password page, queued and failing quietly with the class only.
 */
class SendTwoFactorNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const ENABLED = 'enabled';

    public const REPLACED = 'replaced';

    public const DISABLED = 'disabled';

    public const RESET_BY_SUPPORT = 'reset_by_support';

    public const CODES_REGENERATED = 'codes_regenerated';

    public int $tries = 1;

    public function __construct(
        public readonly int $patientId,
        public readonly string $kind,
    ) {}

    public function handle(PatientMail $mail, BrandSettings $brand): void
    {
        $patient = Patient::find($this->patientId);

        if ($patient === null) {
            return;
        }

        try {
            $delivery = $mail->deliveryOrFail('Two-step verification notice');

            $name = $brand->name;
            $forgot = $mail->portalLink('forgot_path');

            [$subject, $what] = match ($this->kind) {
                self::ENABLED => ['two-step verification is on', 'Two-step verification was just turned on for your account. Signing in now needs a code from your authenticator app.'],
                self::REPLACED => ['your authenticator app was changed', 'The authenticator app for your account was just replaced, and new recovery codes were created. Codes from the old app no longer work.'],
                self::DISABLED => ['two-step verification is off', 'Two-step verification was just turned off for your account, and other devices were signed out.'],
                self::RESET_BY_SUPPORT => ['two-step verification was reset by support', 'Our support team turned off two-step verification for your account, and every device was signed out.'],
                self::CODES_REGENERATED => ['new recovery codes', 'New recovery codes were just created for your account. The old ones no longer work.'],
            };

            $body = <<<TEXT
                Hi,

                {$what}

                If this was you, there is nothing else to do.

                If it was not, set a new password now at {$forgot} and reply to this email to let us know.
                TEXT;

            $mail->send($delivery, new EmailMessage(
                to: $patient->email,
                subject: "{$name}: {$subject}",
                body: $body,
                trackLinks: false,
            ));
        } catch (Throwable $e) {
            Log::error('Two-step verification notice failed to send.', [
                'patient_id' => $patient->getKey(),
                'kind' => $this->kind,
                'exception' => $e::class,
            ]);
        }
    }
}
