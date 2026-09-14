<?php

namespace App\Actions\Leads;

use App\Actions\Attribution\RecordCanonicalEventAction;
use App\Actions\Concerns\Transacts;
use App\Actions\Referral\AttributeLeadAction;
use App\Data\Leads\LeadData;
use App\Events\Leads\LeadCreated;
use App\Models\Lead;
use App\Models\LeadDisposition;
use App\Services\Attribution\LeadEventPayload;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\DataCollection;
use Throwable;

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
            $this->recordCaptureEvents($lead);

            return $lead;
        });

        // ATTRIBUTION RUNS HERE — after the commit, before the event — and the
        // ordering is load-bearing in both directions.
        //
        // After the commit, because a referral may never roll back a lead: a lost
        // commission is recoverable from `referral_clicks`, a lost lead is not.
        // Before the event, because `AttributeLeadAction` replaces `utm_*`,
        // `referrer` and `landing_url` from the click — and `utm_source`,
        // `utm_medium` and `utm_campaign` are in WorkflowServiceProvider's
        // condition allow-list. Dispatching first would let a queued
        // `lead.created` chain route a referred visitor on pre-backfill values.
        // (`referral_*` itself is deliberately NOT in that allow-list.)
        //
        // Wrapped because nothing about attribution is worth failing a request
        // that has already banked a lead.
        if (filled($data->referral_code)) {
            try {
                app(AttributeLeadAction::class)->execute(
                    $lead,
                    $data->referral_code,
                    $data->referral_visitor_id,
                );
            } catch (Throwable $e) {
                Log::error('lead attribution failed', [
                    'lead_id' => $lead->id,
                    'code' => $data->referral_code,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // OUTSIDE the transaction, so a listener can never see — or act on — a
        // lead whose insert then rolled back. Fires for EVERY lead, quiz or
        // checkout; QuizCompleted is the narrower signal dispatched in addition.
        LeadCreated::dispatch($lead);

        return $lead;
    }

    /** Persist source-capture facts with the Lead, before best-effort referral credit. */
    private function recordCaptureEvents(Lead $lead): void
    {
        $payload = app(LeadEventPayload::class)->forLead($lead);
        $events = app(RecordCanonicalEventAction::class);
        $events->execute(
            name: 'lead.captured', source: 'lead.capture', dedupeKey: 'lead:'.$lead->id.':captured',
            occurredAt: $lead->created_at, payload: $payload, lead: $lead,
            origin: 'admin', environment: (string) config('app.env', 'local'),
        );
        if ($lead->quiz_id !== null) {
            $events->execute(
                name: 'quiz.completed', source: 'quiz.capture', dedupeKey: 'lead:'.$lead->id.':quiz-completed',
                occurredAt: $lead->quiz_completed_at, payload: $payload, lead: $lead,
                origin: 'admin', environment: (string) config('app.env', 'local'),
            );
        }
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
