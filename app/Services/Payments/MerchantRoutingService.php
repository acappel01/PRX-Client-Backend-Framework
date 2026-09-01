<?php

namespace App\Services\Payments;

use App\Models\Payments\MerchantAccount;
use Illuminate\Support\Collection;

/**
 * Chooses which merchant account takes a given charge.
 *
 * WHY ROTATION EXISTS. An install runs several accounts and moves between them
 * as they approach their processing limits. That has to happen without anyone
 * editing a setting, and the storefront has to follow the change on its next
 * request rather than after a deploy.
 *
 * TWO RULES SHAPE THE SELECTION:
 *
 *   1. **Weight falls off as an account fills, rather than cutting off.**
 *      An account at 80% of its limit still takes traffic, just less of it —
 *      `weight × (1 - usageRatio)`, floored so it never reaches zero while it
 *      is still eligible. A hard cliff would send every transaction to one
 *      account until the instant it died, which is exactly the spike a
 *      processor flags.
 *   2. **A charge that would BREACH the limit is refused by that account, not
 *      squeezed in.** Eligibility is evaluated against this specific amount,
 *      so a $900 order skips an account with $500 of headroom even though the
 *      account is otherwise healthy.
 *
 * THE ACCOUNT THAT TOKENISES MUST BE THE ACCOUNT THAT CHARGES. The browser
 * mints an opaque token against one gateway's SDK, and that token is worthless
 * at any other. So the selected account travels with the token — the storefront
 * receives `merchant_account_id` in its gateway context and hands it back at
 * submit. Re-running this selector at charge time would be a bug, not a
 * refinement.
 */
class MerchantRoutingService
{
    /**
     * Accounts that could take a charge of this size right now.
     *
     * @return Collection<int, MerchantAccount>
     */
    public function eligible(?float $amount = null, bool $publicCheckout = true): Collection
    {
        return MerchantAccount::query()
            ->where('is_active', true)
            ->when($publicCheckout, fn ($q) => $q->where('supports_public_checkout', true))
            ->get()
            ->reject(fn (MerchantAccount $a) => $this->wouldExceedLimit($a, $amount))
            ->values();
    }

    /**
     * The account to use, or null when nothing can take the charge.
     *
     * Weighted-random rather than "highest weight wins": deterministic ordering
     * would concentrate every transaction on one account until it filled, which
     * is the spike this exists to avoid.
     */
    public function select(?float $amount = null, bool $publicCheckout = true): ?MerchantAccount
    {
        $candidates = $this->eligible($amount, $publicCheckout);

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $weights = $candidates->map(fn (MerchantAccount $a) => $this->effectiveWeight($a));
        $total = (float) $weights->sum();

        if ($total <= 0.0) {
            // Every candidate is weightless. Falling back to the default (then
            // the first) beats returning null: the accounts ARE eligible, and
            // refusing the payment because of a weighting quirk would be worse
            // than an unbalanced one.
            return $candidates->firstWhere('is_default', true) ?? $candidates->first();
        }

        $roll = mt_rand() / mt_getrandmax() * $total;
        $running = 0.0;

        foreach ($candidates as $index => $account) {
            $running += (float) $weights[$index];

            if ($roll <= $running) {
                return $account;
            }
        }

        return $candidates->last();
    }

    /**
     * Configured weight, damped by how full the account is.
     *
     * Floored at a tenth of the configured weight so a nearly-full account
     * still receives the occasional charge rather than silently dropping out —
     * a hard zero here is indistinguishable from a misconfiguration when you
     * are staring at the traffic split.
     */
    public function effectiveWeight(MerchantAccount $account): float
    {
        $weight = max(0.0, (float) ($account->transaction_weight ?? 1));
        $ratio = $this->usageRatio($account);

        if ($ratio === null) {
            return $weight;
        }

        return $weight * max(0.1, 1.0 - min($ratio, 0.95));
    }

    /** Null when the account has no limit to be a fraction of. */
    public function usageRatio(MerchantAccount $account): ?float
    {
        $limit = (float) ($account->monthly_volume_limit ?? 0);

        if ($limit <= 0) {
            return null;
        }

        return max(0.0, (float) ($account->monthly_volume_used ?? 0) / $limit);
    }

    /**
     * Whether THIS charge would push the account past its limit.
     *
     * With no amount the question is only "is it already at the limit" — used
     * when rendering a card form before a total is known.
     */
    public function wouldExceedLimit(MerchantAccount $account, ?float $amount = null): bool
    {
        $limit = (float) ($account->monthly_volume_limit ?? 0);

        if ($limit <= 0) {
            return false;
        }

        $used = (float) ($account->monthly_volume_used ?? 0);

        return round($used + (float) ($amount ?? 0), 2) > round($limit, 2);
    }
}
