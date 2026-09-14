<?php

namespace App\Services\Attribution;

use App\Enums\Integrations\IntegrationCapability;
use App\Integrations\Contracts\ReadsEmailSuppression;
use App\Integrations\IntegrationRegistry;
use App\Models\Attribution\EmailSuppressionObservation;
use App\Models\Integrations\IntegrationIdentity;
use App\Models\Integrations\IntegrationInstance;
use App\Models\Lead;

/** Caller owns the transaction and locks instance and Lead before resolving evidence. */
class EmailSuppressionEvidence
{
    public const MAX_AGE_SECONDS = 300;

    public function identity(Lead $lead, IntegrationInstance $instance): ?IntegrationIdentity
    {
        return IntegrationIdentity::query()->where('integration_instance_id', $instance->id)
            ->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)->lockForUpdate()->first();
    }

    public function available(Lead $lead, IntegrationInstance $instance, ?IntegrationIdentity $identity): bool
    {
        $scope = $instance->settings['suppression_read'] ?? null;
        $driver = app(IntegrationRegistry::class)->provider($instance->provider)['driver'] ?? '';

        return ! $instance->trashed() && $instance->is_active && app(IntegrationRegistry::class)->instanceOffers($instance, IntegrationCapability::Crm)
            && is_a($driver, ReadsEmailSuppression::class, true)
            && filter_var($lead->email, FILTER_VALIDATE_EMAIL) !== false
            && $identity !== null && is_string($identity->remote_id)
            && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $identity->remote_id) === 1
            && is_array($scope) && ($scope['version'] ?? null) === 1
            && is_string($scope['account_id'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $scope['account_id']) === 1
            && is_string($scope['environment'] ?? null) && in_array($scope['environment'], ['testing', 'sandbox', 'production'], true)
            && is_string($instance->credentials['private_key'] ?? null) && trim($instance->credentials['private_key']) !== '';
    }

    public function binding(Lead $lead, IntegrationInstance $instance, ?IntegrationIdentity $identity): string
    {
        return hash_hmac('sha256', json_encode([
            'version' => 1, 'instance' => $instance->id, 'provider' => $instance->provider,
            'credentials' => $instance->credentials, 'scope' => $instance->settings['suppression_read'] ?? null,
            'lead' => $lead->id, 'email' => $lead->email, 'identity' => $identity?->id,
            'profile' => $identity?->remote_id, 'linked_at' => $identity?->last_pushed_at?->toISOString(),
        ], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function current(?Lead $lead, IntegrationInstance $instance, string $environment): ?EmailSuppressionObservation
    {
        if ($lead === null) {
            return null;
        }
        $identity = $this->identity($lead, $instance);
        if (! $this->available($lead, $instance, $identity) || $instance->settings['suppression_read']['environment'] !== $environment) {
            return null;
        }
        // A reservation exists before HTTP. Monotonic request order survives equal clocks, slow responses and process death.
        $latest = EmailSuppressionObservation::query()->where('lead_id', $lead->id)
            ->where('integration_instance_id', $instance->id)->orderByRaw('COALESCE(request_id, id) DESC')->orderByDesc('id')->lockForUpdate()->first();
        if ($latest === null || $latest->started_at->isFuture()
            || $latest->started_at->lessThan(now()->subSeconds(self::MAX_AGE_SECONDS))
            || ! hash_equals($this->binding($lead, $instance, $identity), $latest->evidence['binding'] ?? '')) {
            return null;
        }

        return $latest;
    }
}
