<?php

namespace Tests\Feature\Patient;

use App\Actions\Patient\RevokePatientSessionsAction;
use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Filament\Pages\Settings\ManagePortal;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\SecurityEventsRelationManager;
use App\Models\Patient;
use App\Models\PatientSecurityEvent;
use App\Models\User;
use App\Settings\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertFormSet(['security_events_retention_days' => 730])
            ->fillForm(['security_events_retention_days' => 400])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(400, app(PortalSettings::class)->refresh()->security_events_retention_days);
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
    }
}
