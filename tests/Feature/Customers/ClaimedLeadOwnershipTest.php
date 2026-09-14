<?php

namespace Tests\Feature\Customers;

use App\Actions\Customers\LinkCustomerToClaimedLeadAction;
use App\Models\Commerce\Encounter;
use App\Models\Commerce\Order;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\PatientEmailToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClaimedLeadOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function evidence(Patient $patient): array
    {
        $lead = Lead::factory()->create(['email' => $patient->email]);
        $lead->forceFill(['patient_id' => $patient->id])->save();
        $encounter = Encounter::factory()->create([
            'lead_id' => $lead->id, 'prescribe_rx_patient_id' => $patient->prx_patient_chart_id,
        ]);
        $order = Order::factory()->create(['encounter_id' => $encounter->id]);

        return [$lead, $encounter, $order];
    }

    private function verifiedPatient(): Patient
    {
        return Patient::factory()->withPrxChart()->create(['email_verified_at' => now()]);
    }

    private function assertRefused(Patient $patient, Lead $lead): void
    {
        try {
            app(LinkCustomerToClaimedLeadAction::class)->execute($patient, $lead);
            $this->fail('Unproven or conflicting ownership must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer', $e->errors());
        }
    }

    public function test_verified_claim_links_only_its_trusted_orders_and_is_idempotent(): void
    {
        $patient = $this->verifiedPatient();
        [$lead, $encounter, $order] = $this->evidence($patient);
        $lead->update(['prescribe_rx_patient_id' => 'untrusted-client-chart']);
        $deleted = Order::factory()->create(['encounter_id' => $encounter->id]);
        $deleted->delete();
        $unrelated = Order::factory()->create(['patient_id' => $patient->id]);
        $action = app(LinkCustomerToClaimedLeadAction::class);

        $customer = $action->execute($patient, $lead);
        $this->assertTrue($action->execute($patient, $lead)->is($customer));
        $this->assertSame($customer->id, $lead->fresh()->customer_id);
        $this->assertTrue($lead->fresh()->customer->is($customer));
        $this->assertSame($customer->id, $order->fresh()->customer_id);
        $this->assertNull($order->fresh()->patient_id, 'Do not change legacy portal/chart entitlement.');
        $this->assertNull($unrelated->fresh()->customer_id);
        $this->assertNull($deleted->fresh()->customer_id);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_provider_links', 0);
        $this->assertArrayNotHasKey('customer_id', $lead->fresh()->toArray());
        $this->assertFalse((new Lead)->isFillable('customer_id'));
    }

    public function test_same_email_and_public_embed_ids_do_not_prove_a_claim(): void
    {
        $patient = $this->verifiedPatient();
        [$lead, , $order] = $this->evidence($patient);
        $lead->forceFill(['patient_id' => null, 'prescribe_rx_patient_id' => $patient->prx_patient_chart_id])->save();
        $this->assertRefused($patient, $lead);
        $this->assertNull($lead->fresh()->customer_id);
        $this->assertNull($order->fresh()->customer_id);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_unverified_email_or_chart_and_missing_trusted_evidence_are_denied(): void
    {
        $patient = $this->verifiedPatient();
        [$lead, $encounter] = $this->evidence($patient);
        $patient->forceFill(['email_verified_at' => null])->save();
        $this->assertRefused($patient, $lead);
        $patient->forceFill(['email_verified_at' => now(), 'prx_chart_verified_at' => null])->save();
        $this->assertRefused($patient, $lead);
        $patient->forceFill(['prx_chart_verified_at' => now()])->save();
        $encounter->delete();
        $this->assertRefused($patient, $lead);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_all_order_links_roll_back_when_any_order_has_conflicting_ownership(): void
    {
        $patient = $this->verifiedPatient();
        [$lead, $encounter, $first] = $this->evidence($patient);
        $other = Customer::factory()->create();
        $conflict = Order::factory()->create(['encounter_id' => $encounter->id, 'customer_id' => $other->id]);
        $this->assertRefused($patient, $lead);
        $this->assertNull($lead->fresh()->customer_id);
        $this->assertNull($first->fresh()->customer_id);
        $this->assertSame($other->id, $conflict->fresh()->customer_id);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_conflicting_lead_or_legacy_order_owner_and_deleted_customer_are_denied(): void
    {
        $patient = $this->verifiedPatient();
        [$lead, , $order] = $this->evidence($patient);
        $other = Customer::factory()->create();
        $lead->forceFill(['customer_id' => $other->id])->save();
        $this->assertRefused($patient, $lead);
        $lead->forceFill(['customer_id' => null])->save();
        $order->update(['patient_id' => Patient::factory()->create()->id]);
        $this->assertRefused($patient, $lead);
        $order->update(['patient_id' => null]);
        $ownDeleted = Customer::factory()->create(['portal_account_id' => $patient->id]);
        $ownDeleted->delete();
        $this->assertRefused($patient, $lead);
        $this->assertNull($lead->fresh()->customer_id);
        $this->assertNull($order->fresh()->customer_id);
        $this->assertTrue($ownDeleted->fresh()->trashed());
    }

    public function test_order_on_a_different_chart_under_the_same_lead_is_not_adopted(): void
    {
        $patient = $this->verifiedPatient();
        [$lead, , $order] = $this->evidence($patient);
        $different = Encounter::factory()->create(['lead_id' => $lead->id]);
        $conflict = Order::factory()->create(['encounter_id' => $different->id]);
        $this->assertRefused($patient, $lead);
        $this->assertNull($lead->fresh()->customer_id);
        $this->assertNull($order->fresh()->customer_id);
        $this->assertNull($conflict->fresh()->customer_id);
    }

    private function claimToken(Lead $lead, string $purpose): string
    {
        $plain = PatientEmailToken::newPlainToken();
        PatientEmailToken::create([
            'purpose' => $purpose, 'lead_id' => $lead->id,
            'sent_to' => strtolower($lead->email), 'token_hash' => PatientEmailToken::hash($plain),
            'expires_at' => now()->addHour(),
        ]);

        return $plain;
    }

    public function test_signed_in_mailbox_claim_provisions_and_links_customer_and_order(): void
    {
        $patient = Patient::factory()->create();
        $lead = Lead::factory()->create(['email' => $patient->email]);
        $encounter = Encounter::factory()->create(['lead_id' => $lead->id]);
        $order = Order::factory()->create(['encounter_id' => $encounter->id]);
        $token = $this->claimToken($lead, PatientEmailToken::PURPOSE_CLAIM);
        $this->withToken($patient->createToken('portal', ['patient:*'])->plainTextToken)
            ->postJson('/api/v1/patient/claim', ['token' => $token])->assertOk();

        $customer = Customer::sole();
        $this->assertSame($patient->id, $customer->portal_account_id);
        $this->assertSame($customer->id, $lead->fresh()->customer_id);
        $this->assertSame($customer->id, $order->fresh()->customer_id);
        $this->assertNotNull($patient->fresh()->email_verified_at);
    }

    public function test_create_account_claim_makes_its_order_readable_with_the_new_portal_session(): void
    {
        $lead = Lead::factory()->create();
        $encounter = Encounter::factory()->create(['lead_id' => $lead->id]);
        $order = Order::factory()->create(['encounter_id' => $encounter->id]);
        $token = $this->claimToken($lead, PatientEmailToken::PURPOSE_CREATE_ACCOUNT);
        $response = $this->postJson('/api/v1/patient/auth/create-account', [
            'token' => $token, 'password' => 'correct-horse-battery',
        ])->assertCreated();

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/orders/'.$order->uuid)->assertOk()->assertJsonPath('data.uuid', $order->uuid);
        $this->assertSame(Customer::sole()->id, $lead->fresh()->customer_id);
        $this->assertSame(Customer::sole()->id, $order->fresh()->customer_id);
    }

    public function test_create_account_claim_conflict_rolls_back_account_token_and_all_links(): void
    {
        $lead = Lead::factory()->create();
        $encounter = Encounter::factory()->create(['lead_id' => $lead->id]);
        $other = Customer::factory()->create();
        $order = Order::factory()->create(['encounter_id' => $encounter->id, 'customer_id' => $other->id]);
        $token = $this->claimToken($lead, PatientEmailToken::PURPOSE_CREATE_ACCOUNT);
        $this->postJson('/api/v1/patient/auth/create-account', [
            'token' => $token, 'password' => 'correct-horse-battery',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('customers', 1);
        $this->assertNull(PatientEmailToken::sole()->consumed_at);
        $this->assertNull($lead->fresh()->patient_id);
        $this->assertNull($lead->fresh()->customer_id);
        $this->assertSame($other->id, $order->fresh()->customer_id);
    }

    public function test_signed_in_claim_conflict_does_not_burn_token_or_verify_account(): void
    {
        $patient = Patient::factory()->create();
        $lead = Lead::factory()->create(['email' => $patient->email]);
        $encounter = Encounter::factory()->create(['lead_id' => $lead->id]);
        $other = Customer::factory()->create();
        Order::factory()->create(['encounter_id' => $encounter->id, 'customer_id' => $other->id]);
        $token = $this->claimToken($lead, PatientEmailToken::PURPOSE_CLAIM);
        $this->withToken($patient->createToken('portal', ['patient:*'])->plainTextToken)
            ->postJson('/api/v1/patient/claim', ['token' => $token])->assertUnprocessable();

        $this->assertNull(PatientEmailToken::sole()->consumed_at);
        $this->assertNull($patient->fresh()->email_verified_at);
        $this->assertNull($patient->fresh()->prx_patient_chart_id);
        $this->assertNull($lead->fresh()->patient_id);
        $this->assertNull($lead->fresh()->customer_id);
        $this->assertDatabaseCount('customers', 1);
    }
}
