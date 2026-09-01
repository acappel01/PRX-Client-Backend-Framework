<?php

namespace App\Http\Controllers\Api\V1\Leads;

use App\Actions\Leads\CreateLeadAction;
use App\Data\Leads\LeadData;
use App\Enums\CheckoutPath;
use App\Events\Quiz\QuizCompleted;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Leads\LeadResource;
use App\Models\Lead;
use App\Models\Quiz\Quiz;
use App\Services\Quiz\QuizAnswerValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/leads        Create a lead at checkout initiation.
 * GET  /api/v1/leads/{uuid} Retrieve a lead by UUID (for pre-fill on return visit).
 */
class LeadController extends ApiController
{
    /**
     * Create a new lead.
     *
     * Called when the user submits the first checkout step (name + email + cart).
     * The cart snapshot is passed in from the frontend's live cart state so the
     * backend Lead record captures what was selected at lead-capture time. Binds
     * the lead to the X-Cart-Token session when present.
     *
     * @tags Leads
     *
     * @unauthenticated
     */
    public function store(Request $request, CreateLeadAction $action, QuizAnswerValidator $answers): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date', 'before:-18 years'],
            'gender' => ['nullable', 'string', 'in:male,female,other,prefer_not_to_say'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:8'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'size:2'],

            // The address above is the SHIPPING address — its state decides
            // which licensed clinician can take the encounter. Billing is
            // optional and only required when it differs.
            'billing_same_as_shipping' => ['boolean'],
            'billing_address_line1' => ['nullable', 'required_if:billing_same_as_shipping,false', 'string', 'max:255'],
            'billing_address_line2' => ['nullable', 'string', 'max:255'],
            'billing_city' => ['nullable', 'required_if:billing_same_as_shipping,false', 'string', 'max:100'],
            'billing_state' => ['nullable', 'required_if:billing_same_as_shipping,false', 'string', 'size:2'],
            'billing_postal_code' => ['nullable', 'required_if:billing_same_as_shipping,false', 'string', 'max:16'],
            'billing_country' => ['nullable', 'string', 'size:2'],
            'sms_consent' => ['boolean'],
            'email_consent' => ['boolean'],

            // The wording the client rendered for each consent, snapshotted into
            // the audit. Capped hard: this is descriptive evidence supplied by an
            // unauthenticated caller, so it gets a length bound like every other
            // free-text field on this endpoint.
            'consent_disclosures' => ['array'],
            'consent_disclosures.*.text' => ['nullable', 'string', 'max:2000'],
            'consent_disclosures.*.version' => ['nullable', 'string', 'max:64'],
            'checkout_path' => ['nullable', 'string', 'in:local,prx'],
            'cart_items' => ['nullable', 'array'],
            'cart_subtotal' => ['nullable', 'numeric', 'min:0'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'referrer' => ['nullable', 'url', 'max:2048'],
            'landing_url' => ['nullable', 'url', 'max:2048'],

            // The intake quiz. `quiz_slug` rather than an id: the frontend is
            // handed slugs everywhere else and an id would be the only place
            // it had to know a database key.
            'quiz_slug' => ['nullable', 'string', 'max:255'],
            'quiz_answers' => ['nullable', 'array'],
            'age' => ['nullable', 'integer', 'min:18', 'max:120'],
        ]);

        // Answers are checked against the quiz that produced them, which is
        // what makes `visible_when` a constraint rather than a suggestion —
        // the browser's evaluation is a rendering decision and a submission is
        // just an HTTP request. See QuizAnswerValidator.
        $quiz = null;
        $quizAnswers = null;

        if (filled($validated['quiz_slug'] ?? null)) {
            $quiz = Quiz::query()->active()->where('slug', $validated['quiz_slug'])->first();

            if ($quiz === null) {
                throw ValidationException::withMessages([
                    'quiz_slug' => 'That quiz is not available.',
                ]);
            }

            $result = $answers->validate($quiz, $validated['quiz_answers'] ?? []);

            if ($result['errors'] !== []) {
                throw ValidationException::withMessages($result['errors']);
            }

            $quizAnswers = $result['answers'];
        }

        $data = new LeadData(
            first_name: $validated['first_name'],
            last_name: $validated['last_name'],
            email: $validated['email'],
            phone: $validated['phone'] ?? null,
            date_of_birth: $validated['date_of_birth'] ?? null,
            age: isset($validated['age']) ? (int) $validated['age'] : null,
            gender: $validated['gender'] ?? null,
            address_line1: $validated['address_line1'] ?? null,
            address_line2: $validated['address_line2'] ?? null,
            city: $validated['city'] ?? null,
            state: $validated['state'] ?? null,
            postal_code: $validated['postal_code'] ?? null,
            country: $validated['country'] ?? 'US',
            billing_same_as_shipping: (bool) ($validated['billing_same_as_shipping'] ?? true),
            billing_address_line1: $validated['billing_address_line1'] ?? null,
            billing_address_line2: $validated['billing_address_line2'] ?? null,
            billing_city: $validated['billing_city'] ?? null,
            billing_state: $validated['billing_state'] ?? null,
            billing_postal_code: $validated['billing_postal_code'] ?? null,
            billing_country: $validated['billing_country'] ?? null,
            sms_consent: (bool) ($validated['sms_consent'] ?? false),
            email_consent: (bool) ($validated['email_consent'] ?? false),
            cart_items: $validated['cart_items'] ?? [],
            cart_subtotal: isset($validated['cart_subtotal']) ? (float) $validated['cart_subtotal'] : null,
            checkout_path: CheckoutPath::from($validated['checkout_path'] ?? CheckoutPath::PrescribeRx->value),
            utm_source: $validated['utm_source'] ?? null,
            utm_medium: $validated['utm_medium'] ?? null,
            utm_campaign: $validated['utm_campaign'] ?? null,
            utm_term: $validated['utm_term'] ?? null,
            utm_content: $validated['utm_content'] ?? null,
            referrer: $validated['referrer'] ?? null,
            landing_url: $validated['landing_url'] ?? null,
            user_agent: substr((string) $request->userAgent(), 0, 512),
            ip_address: $request->ip(),
            consent_disclosures: $validated['consent_disclosures'] ?? null,
            cart_ulid: $request->header('X-Cart-Token') ?: null,
            quiz_answers: $quizAnswers,
            quiz_id: $quiz?->id,
        );

        $lead = $action->execute($data);

        // Fired after the lead is safely persisted, and only for a lead that
        // actually came through the quiz. Listeners are queued, so nothing
        // hanging off this — the plan email today, a CRM push tomorrow — can
        // fail the request that produced the lead.
        if ($quiz !== null) {
            QuizCompleted::dispatch($lead);
        }

        return $this->success((new LeadResource($lead))->toArray($request), status: 201);
    }

    /**
     * Retrieve a lead by UUID.
     *
     * Used by the frontend to pre-fill checkout forms on return visits (e.g. the user
     * closes the tab and returns via a recovery email link). The UUID is treated as a
     * bearer credential — only share it with the lead owner.
     *
     * @tags Leads
     *
     * @unauthenticated
     */
    public function show(Lead $lead): JsonResponse
    {
        return $this->success((new LeadResource($lead))->toArray(request()));
    }
}
