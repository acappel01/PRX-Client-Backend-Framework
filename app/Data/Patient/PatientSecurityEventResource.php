<?php

namespace App\Data\Patient;

use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use App\Models\PatientSecurityEvent;
use Spatie\LaravelData\Data;

/**
 * One entry in a patient's own security history, as the portal shows it.
 *
 * A WHITELIST, not a serialisation of the row. Never here: the row id, the
 * address hash, the signature, which operator acted (`actor` says only that one
 * did), and `context.reason` — which would tell a caller whether a failed
 * sign-in was an unknown address or a wrong password.
 */
class PatientSecurityEventResource extends Data
{
    public function __construct(
        public string $type,
        public string $label,
        public string $occurred_at,
        public ?string $ip_address,
        public ?string $user_agent,
        public string $actor,
        public bool $is_current_session,
        public ?int $sessions_revoked,
    ) {}

    public static function fromModel(PatientSecurityEvent $event, ?int $currentTokenId): self
    {
        // The address and browser are the patient's own data, or an unproven
        // visitor's attempt on their account. On a change staff or the system
        // made they are the support desk's, which is not the patient's to see.
        $isStaff = in_array($event->actor_type, [SecurityEventActor::Operator, SecurityEventActor::System], true);

        return new self(
            type: $event->type->value,
            label: $event->type->label(),
            occurred_at: $event->occurred_at->toIso8601String(),
            ip_address: $isStaff ? null : $event->ip_address,
            user_agent: $isStaff ? null : $event->user_agent,
            // Failed attempts and anonymous link requests were not proven to be
            // the patient, and must not read as though they were.
            actor: match ($event->actor_type) {
                SecurityEventActor::Patient => 'you',
                SecurityEventActor::Operator => 'operator',
                SecurityEventActor::System => 'system',
                SecurityEventActor::Anonymous => 'unverified',
            },
            is_current_session: $currentTokenId !== null && $event->token_id === $currentTokenId,
            // Sessions only: on `device_revoked` the count is browsers, not sessions.
            sessions_revoked: $event->type !== SecurityEventType::DeviceRevoked && isset($event->context['revoked']) ? (int) $event->context['revoked'] : null,
        );
    }
}
