<?php

namespace Tests\Feature\Api\V1\Patient\Concerns;

use App\Models\Commerce\Encounter;
use App\Models\Integrations\IntegrationInstance;
use App\Models\Lead;
use App\Models\Patient;
use App\Settings\CommunicationSettings;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;

/**
 * Shared fixtures for the claim flow.
 *
 * Mail runs through the REAL path — capability routing, `LocalMailDriver`, the
 * Laravel mailer building a Symfony message for the Mailgun transport — and is
 * stopped at `MessageSending`, the last point before the network. `Mail::fake()`
 * would not do: its `raw()` is a no-op, so a send through the site mailer would
 * vanish and an assertion that "nothing was sent" would pass for the wrong
 * reason.
 */
trait ClaimFixtures
{
    protected const CHART = '01a07ea4-282e-70d1-b350-6fc58731c738';

    /** @var list<Email> */
    protected array $sentMail = [];

    protected function enableClaimMail(bool $capture = true): void
    {
        $settings = app(CommunicationSettings::class);
        $settings->email_enabled = true;
        $settings->save();

        config([
            'mail.default' => 'mailgun',
            'services.mailgun.domain' => 'mg.example.test',
            'services.mailgun.secret' => 'key-test',
            'portal.url' => 'https://portal.example.test',
        ]);

        IntegrationInstance::create([
            'name' => 'Site mail', 'slug' => 'site-mail', 'provider' => 'local_mail',
            'is_active' => true, 'capabilities' => ['transactional_email'],
        ]);

        if ($capture) {
            $this->captureMail();
        }
    }

    protected function captureMail(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event): bool {
            $this->sentMail[] = $event->message;

            return false;
        });
    }

    /**
     * An order that has been through checkout: the encounter carries the chart
     * id our own server read from the provider. `$chartOnEncounter = null` is a
     * lead anyone could have minted.
     */
    protected function order(array $attributes = [], ?string $chartOnEncounter = self::CHART): Lead
    {
        $lead = Lead::factory()->create(array_merge([
            'email' => 'buyer@example.test',
            'patient_id' => null,
            'prescribe_rx_patient_id' => self::CHART,
        ], $attributes));

        if ($chartOnEncounter !== null) {
            Encounter::factory()->create([
                'lead_id' => $lead->id,
                'prescribe_rx_patient_id' => $chartOnEncounter,
            ]);
        }

        return $lead;
    }

    protected function actingAsPatient(array $attributes = []): Patient
    {
        $patient = Patient::factory()->create(array_merge([
            'email' => 'buyer@example.test',
            'first_name' => 'Sam',
            'prx_patient_chart_id' => null,
            'email_verified_at' => null,
        ], $attributes));

        Sanctum::actingAs($patient, ['*']);

        return $patient;
    }

    /** Request a link as the current session and return the plain token from the email. */
    protected function requestLinkAndReadToken(): string
    {
        $this->postJson('/api/v1/patient/claim-links')->assertStatus(202);

        $this->assertNotEmpty($this->sentMail, 'No claim email was sent.');

        return $this->tokenFrom(end($this->sentMail));
    }

    protected function tokenFrom(Email $email, string $path = 'claim'): string
    {
        preg_match('#https://portal\.example\.test/'.preg_quote($path, '#').'/([A-Za-z0-9_-]{43})#', $email->getTextBody(), $m);

        $this->assertArrayHasKey(1, $m, "The email carries no {$path} link.");

        return $m[1];
    }
}
