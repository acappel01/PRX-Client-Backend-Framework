<?php

namespace App\Console\Commands;

use App\Actions\Customers\EnsureCustomerForPortalAccountAction;
use App\Actions\Customers\MapCustomerToProviderAction;
use App\Models\Patient;
use App\Models\ProviderInstance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BackfillCustomersCommand extends Command
{
    protected $signature = 'customers:backfill {--apply : Persist changes; otherwise roll back each account preview} {--account=* : Restrict to account IDs} {--provider-instance= : Explicit instance key for historical PRX references}';

    protected $description = 'Provision local Customers without changing portal accounts; dry-run by default';

    public function handle(EnsureCustomerForPortalAccountAction $ensure, MapCustomerToProviderAction $map): int
    {
        $instance = null;
        $key = $this->option('provider-instance');
        if ($key !== null) {
            $instance = ProviderInstance::where('key', $key)->first();
            if (! $instance || $instance->provider !== 'prescribe_rx') {
                $this->error('Choose an existing prescribe_rx provider instance.');

                return self::FAILURE;
            }
        }
        $ids = $this->option('account');
        if ($instance && ! $ids) {
            $this->error('Select explicit --account IDs whose historical references belong to this instance.');

            return self::FAILURE;
        }
        foreach ($ids as $id) {
            if (! ctype_digit((string) $id) || (int) $id < 1 || ! Patient::whereKey($id)->exists()) {
                $this->error('Every selected account must be an existing active account ID.');

                return self::FAILURE;
            }
        }
        $failed = 0;
        $processed = 0;
        $previewClaims = [];
        Patient::query()->when($ids, fn ($query) => $query->whereKey($ids))->chunkById(100, function ($accounts) use ($ensure, $map, $instance, &$failed, &$processed, &$previewClaims) {
            foreach ($accounts as $account) {
                try {
                    $provision = function () use ($ensure, $map, $instance, $account, &$previewClaims): void {
                        $customer = $ensure->execute($account);
                        if ($instance && $customer->prx_patient_chart_id) {
                            if ($customer->provider_environment !== null && $customer->provider_environment !== $instance->environment) {
                                throw ValidationException::withMessages(['provider_link' => 'Historical environment conflicts with the selected instance.']);
                            }
                            $claimKey = $instance->id.':'.$customer->prx_patient_chart_id;
                            if (! $this->option('apply') && isset($previewClaims[$claimKey]) && $previewClaims[$claimKey] !== $account->id) {
                                throw ValidationException::withMessages(['provider_link' => 'Another selected account already reserves this chart in the preview.']);
                            }
                            $map->execute($customer, $instance, $customer->prx_patient_chart_id, $customer->prx_patient_id, $customer->prx_patient_number);
                            if (! $this->option('apply')) {
                                $previewClaims[$claimKey] = $account->id;
                            }
                        }
                    };
                    if ($this->option('apply')) {
                        // Retry the whole account unit: an InnoDB deadlock rolls
                        // back the outer transaction, not only a nested mapping.
                        DB::transaction($provision, 3);
                    } else {
                        DB::beginTransaction();
                        try {
                            $provision();
                        } finally {
                            DB::rollBack();
                        }
                    }
                    $processed++;
                } catch (ValidationException $exception) {
                    $failed++;
                    $this->warn('Account '.$account->id.': conflict; no changes applied for this account.');
                }
            }
        });
        $this->info(($this->option('apply') ? 'Applied' : 'Preview only').": {$processed} accounts; {$failed} conflicts.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
