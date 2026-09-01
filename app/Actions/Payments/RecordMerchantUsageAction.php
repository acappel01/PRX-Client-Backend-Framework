<?php

namespace App\Actions\Payments;

use App\Models\Payments\MerchantAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records a captured amount against a merchant account, and retires the
 * account when it reaches its limit.
 *
 * THE ROW IS LOCKED, AND THAT IS THE POINT. Two concurrent checkouts reading
 * `monthly_volume_used` before either writes would both record against a stale
 * figure, and the account would quietly process past its limit — the exact
 * outcome the limit exists to prevent. `lockForUpdate` inside a transaction
 * serialises them.
 *
 * Only ever called AFTER a successful capture. Recording an authorisation that
 * later fails would consume headroom for money that was never taken.
 */
class RecordMerchantUsageAction
{
    public function execute(MerchantAccount $account, float $amount): MerchantAccount
    {
        return DB::transaction(function () use ($account, $amount): MerchantAccount {
            /** @var MerchantAccount $fresh */
            $fresh = MerchantAccount::query()->lockForUpdate()->findOrFail($account->id);

            $fresh->monthly_volume_used = round(max(0.0, (float) ($fresh->monthly_volume_used ?? 0) + $amount), 2);

            $limit = (float) ($fresh->monthly_volume_limit ?? 0);

            if ($fresh->auto_disable_at_limit && $limit > 0 && $fresh->monthly_volume_used >= $limit) {
                $fresh->is_active = false;
                $fresh->auto_disabled_at = now();

                // Loud, because the storefront silently moves to another
                // account from the next request and nobody would otherwise
                // notice a processor going dark until they all had.
                Log::warning('Merchant account auto-disabled at its monthly volume limit.', [
                    'merchant_account_uuid' => $fresh->uuid,
                    'name' => $fresh->name,
                    'used' => $fresh->monthly_volume_used,
                    'limit' => $limit,
                ]);
            }

            $fresh->save();

            return $fresh;
        });
    }
}
