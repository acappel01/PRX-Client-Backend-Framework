<?php

namespace App\Models;

use App\Enums\CheckoutPath;
use App\Enums\Payments\LeadPaymentStatus;
use App\Models\Commerce\Encounter;
use App\Models\Quiz\QuizQuestion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'cart_ulid',
        'status',
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'age',
        'gender',
        'quiz_answers',
        'quiz_id',
        'quiz_completed_at',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'billing_same_as_shipping',
        'billing_address_line1',
        'billing_address_line2',
        'billing_city',
        'billing_state',
        'billing_postal_code',
        'billing_country',
        'payment_status',
        'payment_gateway_provider',
        'merchant_account_uuid',
        'payment_transaction_id',
        'payment_authorization_code',
        'payment_amount',
        'provider_customer_profile_id',
        'provider_payment_profile_id',
        'card_brand',
        'card_last_four',
        'card_exp_month',
        'card_exp_year',
        'payment_processed_at',
        'payment_failure_reason',
        'sms_consent',
        'email_consent',
        'consent_given_at',
        'cart_items',
        'cart_subtotal',
        'checkout_path',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'referrer',
        'landing_url',
        'user_agent',
        'ip_address',
        'prescribe_rx_encounter_id',
        'prescribe_rx_patient_id',
        'handed_off_at',
        'completed_at',
        'prescribe_rx_response',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            // NOT LeadStatus::class. Dispositions are operator-defined rows now,
            // so an install that adds 'quiz_complete' would throw a ValueError
            // on every read if this stayed an enum cast. The enum survives as
            // the slugs the code itself writes; see App\Models\LeadDisposition.
            'status' => 'string',
            'checkout_path' => CheckoutPath::class,
            'date_of_birth' => 'date',
            'age' => 'integer',
            'quiz_answers' => 'array',
            'quiz_completed_at' => 'datetime',
            'plan_sent_at' => 'datetime',
            'consent_given_at' => 'datetime',
            'handed_off_at' => 'datetime',
            'completed_at' => 'datetime',
            'sms_consent' => 'boolean',
            'email_consent' => 'boolean',
            'cart_items' => 'array',
            'billing_same_as_shipping' => 'boolean',
            'payment_status' => LeadPaymentStatus::class,
            'payment_amount' => 'decimal:2',
            'payment_processed_at' => 'datetime',
            'prescribe_rx_response' => 'array',
            'cart_subtotal' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lead $lead): void {
            if (blank($lead->uuid)) {
                $lead->uuid = (string) Str::uuid();
            }
            if (blank($lead->status)) {
                $lead->status = LeadDisposition::defaultSlug();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    /**
     * The disposition row behind `status`.
     *
     * Matched on SLUG, not on a foreign key — `leads.status` already held these
     * strings before dispositions were rows, and keeping it that way meant no
     * data migration and no change to what the API emits.
     *
     * May be null: a slug can outlive its row if one is force-deleted around the
     * model guards. Callers should fall back to the raw slug rather than assume.
     */
    public function disposition(): BelongsTo
    {
        return $this->belongsTo(LeadDisposition::class, 'status', 'slug');
    }

    /**
     * The quiz answers as label/value pairs an operator can read.
     *
     * `quiz_answers` is keyed by question SLUG, which is the right storage
     * shape — retiring a question neither cascades away history nor blocks a
     * deletion — but it makes the raw column unreadable in the admin.
     *
     * Labels are resolved from the questions THAT STILL EXIST, and an answer
     * whose question has since been retired or renamed still renders, under its
     * slug. Dropping it would hide real marketing data on the grounds that the
     * quiz moved on; showing the slug is honest about what happened. Likewise
     * option values are mapped to their labels where the option survives.
     *
     * @return array<int, array{slug: string, label: string, value: string, retired: bool}>
     */
    public function quizAnswersForDisplay(): array
    {
        $answers = $this->quiz_answers ?? [];

        if ($answers === []) {
            return [];
        }

        $questions = $this->quiz_id === null
            ? collect()
            : QuizQuestion::query()
                ->with('options:id,quiz_question_id,value,label')
                ->where('quiz_id', $this->quiz_id)
                ->get(['id', 'slug', 'prompt'])
                ->keyBy('slug');

        $rows = [];

        foreach ($answers as $slug => $value) {
            $question = $questions->get($slug);

            $rows[] = [
                'slug' => (string) $slug,
                'label' => $question?->prompt ?: (string) $slug,
                'value' => $this->presentAnswer($value, $question),
                'retired' => $question === null,
            ];
        }

        return $rows;
    }

    /** Flatten one answer to something displayable, mapping option values to labels. */
    private function presentAnswer(mixed $value, mixed $question): string
    {
        $labels = $question?->options
            ?->mapWithKeys(fn ($o) => [$o->value => $o->label])
            ->all() ?? [];

        if (is_array($value)) {
            return implode(', ', array_map(
                fn ($v) => (string) ($labels[$v] ?? (is_scalar($v) ? $v : json_encode($v))),
                $value,
            ));
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) ($labels[$value] ?? (is_scalar($value) ? $value : json_encode($value)));
    }

    /**
     * Every consent decision ever recorded for this lead, newest first.
     *
     * Append-only, so this is a full history: a withdrawal is a row with
     * `granted = false` rather than the absence of one. The booleans on this
     * model are the current-state summary; these are the evidence.
     */
    public function consents(): HasMany
    {
        return $this->hasMany(LeadConsent::class)->orderByDesc('consented_at');
    }

    /**
     * The visitor's age, from the best source this lead actually has.
     *
     * `date_of_birth` wins when present because it is what a completed
     * clinical intake captured and it stays correct as time passes; `age` is
     * what the marketing quiz captured and is a snapshot of the day it was
     * answered. Returns null when neither exists — which the recommendation
     * resolver reads as "not asked", not as "no restriction applies".
     */
    public function effectiveAge(): ?int
    {
        return $this->date_of_birth?->age ?? $this->age;
    }
}
