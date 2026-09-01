<?php

namespace App\Data\Leads;

use App\Enums\CheckoutPath;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class LeadData extends Data
{
    /**
     * @param  DataCollection<int, CartItemData>|array<int, CartItemData>  $cart_items
     */
    public function __construct(
        #[Required, Max(255)]
        public string $first_name,
        #[Required, Max(255)]
        public string $last_name,
        #[Required, Email, Max(255)]
        public string $email,
        #[Max(64)]
        public ?string $phone = null,
        public ?string $date_of_birth = null,

        // What the intake quiz collected. Coexists with date_of_birth rather
        // than replacing it — the quiz asks an age, a clinical intake captures
        // a birth date, and back-computing one from the other fabricates data.
        public ?int $age = null,
        #[Max(32)]
        public ?string $gender = null,
        #[Max(255)]
        public ?string $address_line1 = null,
        #[Max(255)]
        public ?string $address_line2 = null,
        #[Max(255)]
        public ?string $city = null,
        #[Max(8)]
        public ?string $state = null,
        #[Max(16)]
        public ?string $postal_code = null,
        #[Max(2)]
        public string $country = 'US',

        // Billing, only meaningful when it differs from shipping. Defaults to
        // mirroring, which is both the common case and the behaviour before
        // billing was collected at all.
        public bool $billing_same_as_shipping = true,
        #[Max(255)]
        public ?string $billing_address_line1 = null,
        #[Max(255)]
        public ?string $billing_address_line2 = null,
        #[Max(255)]
        public ?string $billing_city = null,
        #[Max(2)]
        public ?string $billing_state = null,
        #[Max(16)]
        public ?string $billing_postal_code = null,
        #[Max(2)]
        public ?string $billing_country = null,
        public bool $sms_consent = false,
        public bool $email_consent = false,
        #[DataCollectionOf(CartItemData::class)]
        public array|DataCollection $cart_items = [],
        public ?float $cart_subtotal = null,

        // The completed quiz. Already validated against the quiz definition by
        // QuizAnswerValidator before it reaches here, so this carries only
        // answers to questions that were genuinely askable.
        public ?array $quiz_answers = null,
        public ?int $quiz_id = null,
        #[WithCast(EnumCast::class)]
        public CheckoutPath $checkout_path = CheckoutPath::PrescribeRx,
        public ?string $utm_source = null,
        public ?string $utm_medium = null,
        public ?string $utm_campaign = null,
        public ?string $utm_term = null,
        public ?string $utm_content = null,
        #[Max(2048)]
        public ?string $referrer = null,
        #[Max(2048)]
        public ?string $landing_url = null,
        #[Max(512)]
        public ?string $user_agent = null,
        #[Max(45)]
        public ?string $ip_address = null,
        public ?string $notes = null,
        public ?string $cart_ulid = null,

        /**
         * The consent sentences the frontend ACTUALLY RENDERED, per channel:
         * ['email' => ['text' => '...', 'version' => '...'], 'sms' => [...]].
         *
         * Supplied by the client because the client is the only party that knows
         * what it displayed — the wording lives in editable operator copy
         * (`quiz_questions.config`) that may have changed between page load and
         * submit. This is the same principle as reporting what was sent rather
         * than what was intended.
         *
         * NOT TRUSTED AS AUTHORISATION. It is descriptive evidence stored
         * alongside server-derived IP and user-agent; the booleans still decide
         * whether consent was given. A client that lies here forges only its own
         * record of the sentence, which is why the field is text and not a flag.
         *
         * @var array<string, array{text?: string, version?: string}>|null
         */
        public ?array $consent_disclosures = null,
    ) {}
}
