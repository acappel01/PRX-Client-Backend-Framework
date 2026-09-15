<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use LogicException;

/** One account mutex across independently verified duplicate local merchant rows. */
class PaymentAssociationLock
{
    public function acquire(string $canonicalAccountKey, string $environment): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An association lock requires a transaction.');
        }
        DB::table('payment_association_scopes')->insertOrIgnore(['canonical_account_key' => $canonicalAccountKey, 'environment' => $environment]);

        return (int) DB::table('payment_association_scopes')->where('canonical_account_key', $canonicalAccountKey)
            ->where('environment', $environment)->lockForUpdate()->sole()->id;
    }
}
