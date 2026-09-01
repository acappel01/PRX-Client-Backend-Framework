<?php

namespace App\Actions\Leads;

use App\Actions\Concerns\Transacts;
use App\Data\Leads\LeadData;
use App\Events\Leads\LeadCreated;
use App\Models\Lead;
use App\Models\LeadDisposition;
use Spatie\LaravelData\DataCollection;

class CreateLeadAction
{
    use Transacts;

    public function __construct(private readonly RecordConsentAction $recordConsent) {}

    public function execute(LeadData $data): Lead
    {
        $lead = $this->tx(function () use ($data) {
            $lead = Lead::create([
                // The operator's configured starting stage, not a hardcoded one.
                // Falls back to the LeadStatus::New_ slug if no row is marked
                // default — see LeadDisposition::defaultSlug().
                'status' => LeadDisposition::defaultSlug(),
                'first_name' => $data->first_name,
                'last_name' => $data->last_name,
                'email' => $data->email,
                'phone' => $data->phone,
                'date_of_birth' => $data->date_of_birth,
                'age' => $data->age,
                'gender' => $data->gender,
                'address_line1' => $data->address_line1,
                'address_line2' => $data->address_line2,
                'city' => $data->city,
                'state' => $data->state,
                'postal_code' => $data->postal_code,
                'country' => $data->country,
                'billing_same_as_shipping' => $data->billing_same_as_shipping,
                'billing_address_line1' => $data->billing_address_line1,
                'billing_address_line2' => $data->billing_address_line2,
                'billing_city' => $data->billing_city,
                'billing_state' => $data->billing_state,
                'billing_postal_code' => $data->billing_postal_code,
                'billing_country' => $data->billing_country,
                'sms_consent' => $data->sms_consent,
                'email_consent' => $data->email_consent,
                'consent_given_at' => ($data->sms_consent || $data->email_consent) ? now() : null,
                'cart_items' => $this->serializeCartItems($data),
                'cart_subtotal' => $data->cart_subtotal,
                'checkout_path' => $data->checkout_path,
                'utm_source' => $data->utm_source,
                'utm_medium' => $data->utm_medium,
                'utm_campaign' => $data->utm_campaign,
                'utm_term' => $data->utm_term,
                'utm_content' => $data->utm_content,
                'referrer' => $data->referrer,
                'landing_url' => $data->landing_url,
                'user_agent' => $data->user_agent,
                'ip_address' => $data->ip_address,
                'notes' => $data->notes,
                'cart_ulid' => $data->cart_ulid,
                'quiz_answers' => $data->quiz_answers,
                'quiz_id' => $data->quiz_id,
                // Stamped only for a lead that actually came through the quiz,
                // so it doubles as the flag separating funnel leads from cart
                // leads without anyone inspecting the JSON.
                'quiz_completed_at' => $data->quiz_id !== null ? now() : null,
            ]);

            $this->recordConsents($lead, $data);

            return $lead;
        });

        // OUTSIDE the transaction, so a listener can never see — or act on — a
        // lead whose insert then rolled back. Fires for EVERY lead, quiz or
        // checkout; QuizCompleted is the narrower signal dispatched in addition.
        LeadCreated::dispatch($lead);

        return $lead;
    }

    /**
     * Snapshot what was consented to, per channel.
     *
     * A row is written when consent was GRANTED, or when the disclosure text
     * for that channel was supplied — because "we showed them the SMS opt-in and
     * they left it unticked" is evidence, and losing it makes a later complaint
     * unanswerable. Silence about a channel writes nothing: this install cannot
     * tell the difference between "declined" and "never asked" unless the
     * frontend says which sentence it rendered.
     */
    private function recordConsents(Lead $lead, LeadData $data): void
    {
        $disclosures = $data->consent_disclosures ?? [];

        foreach (['email' => $data->email_consent, 'sms' => $data->sms_consent] as $channel => $granted) {
            $disclosure = $disclosures[$channel] ?? null;

            if (! $granted && $disclosure === null) {
                continue;
            }

            $this->recordConsent->execute(
                lead: $lead,
                channel: $channel,
                granted: $granted,
                text: is_array($disclosure) ? ($disclosure['text'] ?? null) : null,
                version: is_array($disclosure) ? ($disclosure['version'] ?? null) : null,
                source: $data->quiz_id !== null ? 'quiz' : 'checkout',
                ip: $data->ip_address,
                userAgent: $data->user_agent,
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeCartItems(LeadData $data): array
    {
        if ($data->cart_items instanceof DataCollection) {
            return $data->cart_items->toArray();
        }

        return collect($data->cart_items)
            ->map(fn ($item) => is_array($item) ? $item : $item->toArray())
            ->all();
    }
}
