<?php

namespace Tests\Feature\Patient;

use App\Actions\Patient\RevokePatientSessionsAction;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Filament\Pages\Settings\ManagePortal;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\SecurityEventsRelationManager;
use App\Jobs\Patient\SendTwoFactorNoticeJob;
use App\Models\Patient;
use App\Models\PatientSecurityEvent;
use App\Models\User;
use App\Services\Patient\TwoFactor;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What operators do to a patient account, and what they can see of it.
 */
class PatientSecurityOperatorTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');

        $this->operator = User::factory()->create()->refresh();
        $this->operator->assignRole('super_admin');
    }

    // ─── Observer ────────────────────────────────────────────────────

    public function test_an_operator_changing_the_email_or_chart_is_recorded_as_them(): void
    {
        $patient = Patient::factory()->create();
        $this->actingAs($this->operator);

        $patient->update(['email' => 'moved@example.test', 'prx_patient_chart_id' => 'chart-1']);

        $this->assertSame(['email_changed', 'chart_link_changed'], PatientSecurityEvent::orderBy('id')->pluck('type')->map->value->all());

        $changed = PatientSecurityEvent::where('type', 'email_changed')->sole();
        $this->assertSame(SecurityEventActor::Operator, $changed->actor_type);
        $this->assertSame($this->operator->id, $changed->actor_user_id);
        $this->assertSame(PatientSecurityEvent::subjectHash('moved@example.test'), $changed->subject_hash);
    }

    /** The patient's own flows set these columns and record their own events. */
    public function test_a_change_with_no_operator_signed_in_records_nothing(): void
    {
        $patient = Patient::factory()->create();

        $patient->update(['prx_patient_chart_id' => 'chart-1']);

        $this->assertSame(0, PatientSecurityEvent::count());
    }

    public function test_deleting_an_account_ends_its_sessions_so_a_restore_starts_signed_out(): void
    {
        $patient = Patient::factory()->create();
        $patient->createToken('phone');
        $patient->createToken('laptop');
        $this->actingAs($this->operator);

        $patient->delete();

        $this->assertSame(0, $patient->tokens()->count());
        $deleted = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::AccountDeleted, $deleted->type);
        $this->assertSame(['reason' => 'account_deleted', 'revoked' => 2], $deleted->context);
        $this->assertSame($this->operator->id, $deleted->actor_user_id);

        $patient->restore();

        $this->assertSame(SecurityEventType::AccountRestored, PatientSecurityEvent::latest('id')->first()->type);
        $this->assertSame(0, $patient->tokens()->count());
    }

    public function test_a_permanent_delete_is_recorded_once_and_the_row_outlives_the_patient(): void
    {
        $patient = Patient::factory()->create();
        $patient->createToken('phone');
        $this->actingAs($this->operator);

        $patient->forceDelete();

        $event = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::AccountPurged, $event->type);
        $this->assertNull($event->patient_id);
        $this->assertSame($patient->uuid, $event->patient_uuid);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    // ─── Filament ────────────────────────────────────────────────────

    public function test_sign_out_everywhere_ends_every_session_and_is_recorded_as_the_operator(): void
    {
        $patient = Patient::factory()->create();
        $patient->createToken('phone');
        $patient->createToken('laptop');
        $patient->createToken('tablet');
        $this->actingAs($this->operator);

        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->callAction('signOutEverywhere')
            ->assertHasNoActionErrors();

        $this->assertSame(0, $patient->tokens()->count());

        $event = PatientSecurityEvent::sole();
        $this->assertSame(SecurityEventType::SessionsRevoked, $event->type);
        $this->assertSame(SecurityEventActor::Operator, $event->actor_type);
        $this->assertSame($this->operator->id, $event->actor_user_id);
        $this->assertSame(['reason' => 'operator', 'revoked' => 3], $event->context);
    }

    public function test_sign_out_everywhere_is_hidden_from_an_operator_who_cannot_edit_patients(): void
    {
        $patient = Patient::factory()->create();
        $viewer = User::factory()->create();
        foreach (['ViewAny:Patient', 'View:Patient'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->actingAs($viewer);

        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->assertActionHidden('signOutEverywhere');
    }

    public function test_resetting_two_step_clears_it_signs_out_and_records_the_operator(): void
    {
        Bus::fake([SendTwoFactorNoticeJob::class]);
        $patient = Patient::factory()->create();
        $patient->forceFill(['two_factor_secret' => app(TwoFactor::class)->newSecret(), 'two_factor_confirmed_at' => now()])->save();
        app(TwoFactor::class)->replaceRecoveryCodes($patient);
        $patient->createToken('phone');
        $this->actingAs($this->operator);

        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->callAction('resetTwoFactor')
            ->assertHasNoActionErrors();

        $fresh = $patient->fresh();
        $this->assertFalse($fresh->hasTwoFactor());
        $this->assertSame(0, $fresh->recoveryCodes()->count());
        $this->assertSame(0, $patient->tokens()->count());

        $removed = PatientSecurityEvent::where('type', SecurityEventType::TwoFactorRemoved)->sole();
        $this->assertSame(SecurityEventActor::Operator, $removed->actor_type);
        $this->assertSame($this->operator->id, $removed->actor_user_id);
        $this->assertSame(['reason' => 'operator_reset'], $removed->context);
        $this->assertEquals(['reason' => 'two_factor_reset', 'revoked' => 1], PatientSecurityEvent::where('type', SecurityEventType::SessionsRevoked)->sole()->context);
        Bus::assertDispatched(SendTwoFactorNoticeJob::class, fn ($job) => $job->kind === SendTwoFactorNoticeJob::RESET_BY_SUPPORT);
    }

    public function test_reset_two_step_is_only_offered_when_the_patient_has_it(): void
    {
        $patient = Patient::factory()->create();
        $this->actingAs($this->operator);

        Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
            ->assertActionHidden('resetTwoFactor')
            ->assertSeeInOrder(['Two-step verification', 'Off']);
    }

    public function test_the_security_history_renders_on_the_patient_record(): void
    {
        $patient = Patient::factory()->create();
        $patient->createToken('phone');
        $this->actingAs($this->operator);
        app(RevokePatientSessionsAction::class)->execute($patient, $this->operator->id);

        Livewire::test(SecurityEventsRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewPatient::class])
            ->assertOk()
            ->assertCanSeeTableRecords($patient->securityEvents)
            ->assertSee('Signed out of other sessions')
            ->assertSee('1 session(s) ended')
            ->assertSee($this->operator->name);
    }

    // ─── Settings ────────────────────────────────────────────────────

    public function test_the_retention_setting_saves_through_the_page(): void
    {
        $this->actingAs($this->operator);

        Livewire::test(ManagePortal::class)
            ->assertFormSet(['security_events_retention_days' => 730, 'session_idle_minutes' => 30, 'session_max_hours' => 12, 'two_factor_policy' => 'off'])
            ->fillForm(['security_events_retention_days' => 400, 'session_idle_minutes' => 15, 'session_max_hours' => 8, 'two_factor_policy' => 'optional'])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = app(PortalSettings::class)->refresh();
        $this->assertSame(400, $stored->security_events_retention_days);
        $this->assertSame(15, $stored->session_idle_minutes);
        $this->assertSame(8, $stored->session_max_hours);
        $this->assertSame('optional', $stored->two_factor_policy);
    }

    /** The KeyValue field's own dehydrated shape, through getState → DTO → action — not a hand-built array. */
    public function test_requirement_wording_saves_through_the_page(): void
    {
        $this->actingAs($this->operator);

        Livewire::test(ManagePortal::class)
            ->fillForm(['requirement_labels' => ['id_front' => 'A photo of the front of your ID', 'vitals' => 'Your weight and height']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['id_front' => 'A photo of the front of your ID', 'vitals' => 'Your weight and height'],
            app(PortalSettings::class)->refresh()->requirement_labels,
        );
    }

    public function test_the_retention_setting_refuses_values_outside_its_bounds(): void
    {
        $this->actingAs($this->operator);

        foreach ([29, 2556] as $days) {
            Livewire::test(ManagePortal::class)
                ->fillForm(['security_events_retention_days' => $days])
                ->call('save')
                ->assertHasFormErrors(['security_events_retention_days']);
        }

        $this->assertSame(730, app(PortalSettings::class)->refresh()->security_events_retention_days);

        foreach (['session_idle_minutes' => [4, 241], 'session_max_hours' => [0, 721]] as $field => $values) {
            foreach ($values as $value) {
                Livewire::test(ManagePortal::class)
                    ->fillForm([$field => $value])
                    ->call('save')
                    ->assertHasFormErrors([$field]);
            }
        }

        $stored = app(PortalSettings::class)->refresh();
        $this->assertSame(30, $stored->session_idle_minutes);
        $this->assertSame(12, $stored->session_max_hours);
    }
}
