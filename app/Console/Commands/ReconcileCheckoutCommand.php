<?php

namespace App\Console\Commands;

use App\Actions\Checkout\FinalizePrescribeRxCheckoutAction;
use App\Models\Commerce\CheckoutAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ReconcileCheckoutCommand extends Command
{
    protected $signature = 'checkout:reconcile {attempt-uuid} {--provider-instance=} {--apply} {--reason=}';

    protected $description = 'Preview or apply local-only finalization from a recorded provider receipt; never contacts the provider';

    public function handle(FinalizePrescribeRxCheckoutAction $finalize): int
    {
        $key = $this->option('provider-instance');
        $reason = $this->option('reason');
        $apply = (bool) $this->option('apply');
        if (! Str::isUuid($this->argument('attempt-uuid')) || ! is_string($key) || trim($key) === ''
            || ($apply && (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 1000))) {
            $this->error('An attempt UUID and provider instance are required; apply also requires a reason of at most 1000 characters.');

            return self::FAILURE;
        }
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $attempt = CheckoutAttempt::where('uuid', $this->argument('attempt-uuid'))->firstOrFail();
            $before = $attempt->status;
            $finalize->execute($attempt, $key, is_string($reason) && trim($reason) !== '' ? $reason : 'Preview only');
            $after = $attempt->fresh()->status;
            if ($apply) {
                DB::commit();
                $this->info($before === $after ? 'Already completed; no changes applied.' : 'Local checkout finalized and reconciliation audit recorded.');
            } else {
                DB::rollBack();
                $this->info($before === $after ? 'Preview: already completed; no changes needed.' : 'Preview: local finalization is valid. No changes persisted.');
            }

            return self::SUCCESS;
        } catch (Throwable) {
            if (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            // Never echo provider identifiers, receipt data, or exception text.
            $this->error('No changes applied. The recorded receipt, binding, or checkout state could not be safely reconciled.');

            return self::FAILURE;
        }
    }
}
