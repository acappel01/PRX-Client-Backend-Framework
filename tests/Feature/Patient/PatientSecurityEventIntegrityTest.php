<?php

namespace Tests\Feature\Patient;

use App\Data\Patient\RequestContext;
use App\Enums\Patient\SecurityEventType;
use App\Models\Patient;
use App\Models\PatientSecurityEvent;
use App\Services\Patient\PatientSecurityLog;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * The log's promises: append-only through the application, edits detectable by
 * signature, retention by setting, and a user agent that cannot break a write.
 */
class PatientSecurityEventIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function record(?Patient $patient = null, array $context = ['reason' => 'operator', 'revoked' => 2]): PatientSecurityEvent
    {
        return app(PatientSecurityLog::class)->record(
            SecurityEventType::SessionsRevoked,
            patient: $patient ?? Patient::factory()->create(),
            client: new RequestContext('192.0.2.1', 'Test Browser'),
            tokenId: 42,
            context: $context,
        );
    }

    // ─── Append-only ─────────────────────────────────────────────────

    public function test_an_event_cannot_be_edited_through_the_model(): void
    {
        $event = $this->record();

        $this->expectException(RuntimeException::class);

        $event->update(['ip_address' => '10.0.0.1']);
    }

    public function test_an_event_cannot_be_deleted_through_the_model(): void
    {
        $event = $this->record();

        $this->expectException(RuntimeException::class);

        $event->delete();
    }

    // ─── Signature ───────────────────────────────────────────────────

    /** Written at a fractional second, which no store keeps. */
    public function test_an_event_read_back_from_the_database_verifies(): void
    {
        $this->travelTo(now()->setMicrosecond(987654));

        $event = $this->record();

        $this->assertTrue($event->hasValidIntegrity());
        $this->assertTrue(PatientSecurityEvent::findOrFail($event->id)->hasValidIntegrity());
    }

    /** Every signed column, edited with raw SQL the way someone with database access would. */
    public function test_editing_any_signed_column_breaks_verification(): void
    {
        $edits = [
            'ip_address' => '10.9.9.9',
            'user_agent' => 'Forged',
            'type' => 'logout',
            'actor_type' => 'operator',
            'actor_user_id' => 7,
            'token_id' => 43,
            'patient_uuid' => '00000000-0000-0000-0000-000000000000',
            'subject_hash' => str_repeat('0', 64),
            'context' => json_encode(['reason' => 'operator', 'revoked' => 0]),
            'occurred_at' => now()->subYear()->format('Y-m-d H:i:s'),
        ];

        foreach ($edits as $column => $value) {
            $event = $this->record();

            DB::table('patient_security_events')->where('id', $event->id)->update([$column => $value]);

            $this->assertFalse(PatientSecurityEvent::findOrFail($event->id)->hasValidIntegrity(), "Editing {$column} went undetected.");
        }
    }

    /** MySQL's JSON type reorders keys; the signature must not depend on order. */
    public function test_context_key_order_does_not_affect_the_signature(): void
    {
        $event = $this->record(context: ['revoked' => 2, 'reason' => 'operator']);

        DB::table('patient_security_events')->where('id', $event->id)->update(['context' => '{"reason":"operator","revoked":2}']);

        $this->assertTrue(PatientSecurityEvent::findOrFail($event->id)->hasValidIntegrity());
    }

    public function test_rotating_app_key_keeps_history_verifiable(): void
    {
        $event = $this->record();
        $old = config('app.key');

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => []]);
        $this->assertFalse(PatientSecurityEvent::findOrFail($event->id)->hasValidIntegrity());

        config(['app.previous_keys' => [$old]]);
        $this->assertTrue(PatientSecurityEvent::findOrFail($event->id)->hasValidIntegrity());
    }

    public function test_a_purged_patient_leaves_a_row_that_still_verifies_and_names_them(): void
    {
        $patient = Patient::factory()->create();
        $event = $this->record($patient);

        $patient->forceDelete();

        $event = PatientSecurityEvent::findOrFail($event->id);
        $this->assertNull($event->patient_id);
        $this->assertSame($patient->uuid, $event->patient_uuid);
        $this->assertTrue($event->hasValidIntegrity());
    }

    // ─── Verify command ──────────────────────────────────────────────

    public function test_the_verify_command_passes_untouched_history(): void
    {
        $this->record();
        $this->record();

        $this->assertSame(0, Artisan::call('patient-security-events:verify'));
        $this->assertStringContainsString('2 events verified', Artisan::output());
    }

    public function test_the_verify_command_fails_and_logs_an_edited_row(): void
    {
        $this->record();
        $edited = $this->record();
        DB::table('patient_security_events')->where('id', $edited->id)->update(['ip_address' => '10.9.9.9']);
        Log::spy();

        $this->assertSame(1, Artisan::call('patient-security-events:verify'));

        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context): bool => $context['event_ids'] === [$edited->id]);
    }

    /** `patient_id` is unsigned; re-pointing it at another patient is caught through the signed uuid. */
    public function test_the_verify_command_catches_a_row_moved_to_another_patient(): void
    {
        $event = $this->record();
        $other = Patient::factory()->create();

        DB::table('patient_security_events')->where('id', $event->id)->update(['patient_id' => $other->id]);

        $this->assertSame(1, Artisan::call('patient-security-events:verify'));
    }

    // ─── Retention ───────────────────────────────────────────────────

    public function test_the_prune_removes_only_events_older_than_the_setting(): void
    {
        $settings = app(PortalSettings::class);
        $settings->security_events_retention_days = 60;
        $settings->save();

        $this->travelTo(now()->subDays(61));
        $old = $this->record();
        $this->travelBack();
        $this->travelTo(now()->subDays(59));
        $young = $this->record();
        $this->travelBack();

        Artisan::call('model:prune', ['--model' => [PatientSecurityEvent::class]]);

        $this->assertSame([$young->id], PatientSecurityEvent::pluck('id')->all());
        $this->assertNotNull($old);
    }

    public function test_the_prune_never_goes_below_thirty_days_whatever_is_stored(): void
    {
        DB::table('settings')->where('group', 'portal')->where('name', 'security_events_retention_days')->update(['payload' => json_encode(1)]);

        $this->travelTo(now()->subDays(31));
        $this->record();
        $this->travelBack();
        $this->travelTo(now()->subDays(29));
        $kept = $this->record();
        $this->travelBack();

        Artisan::call('model:prune', ['--model' => [PatientSecurityEvent::class]]);

        // The 31-day row going proves the stored 1 was read (730 would keep it);
        // the 29-day row staying proves the floor.
        $this->assertSame([$kept->id], PatientSecurityEvent::pluck('id')->all());
    }

    // ─── Input hygiene ───────────────────────────────────────────────

    public function test_a_hostile_user_agent_is_cleaned_not_fatal(): void
    {
        $context = new RequestContext('192.0.2.1', "  \xC3\x28bad bytes ".str_repeat('x', 600));

        $this->assertTrue(mb_check_encoding($context->userAgent, 'UTF-8'));
        $this->assertSame(RequestContext::USER_AGENT_MAX, mb_strlen($context->userAgent));
    }
}
