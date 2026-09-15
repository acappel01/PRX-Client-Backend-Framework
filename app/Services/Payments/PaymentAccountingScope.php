<?php

namespace App\Services\Payments;

use App\Data\Payments\OrderFinancialResolution;
use App\Models\Commerce\Order;
use App\Models\Payments\PaymentAccountingJournal;
use App\Models\Payments\PaymentAccountingLine;
use App\Models\Payments\PaymentFinancialObservation;
use App\Models\Payments\PaymentFinancialReadRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal exact economic identities; caller retains financial account/order locks until commit. */
class PaymentAccountingScope
{
    public function __construct(private readonly PaymentLedgerScope $ledger) {}

    public function candidates(Order $order, OrderFinancialResolution $financial): array
    {
        if (DB::transactionLevel() === 0 || ! $financial->qualified_reported_amounts || $financial->status !== 'qualified_reported'
            || $financial->order_uuid !== $order->uuid || $financial->currency !== $order->currency) {
            $this->reject();
        }
        $result = [];
        foreach ($financial->observation_ids as $id) {
            $observation = PaymentFinancialObservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $request = PaymentFinancialReadRequest::whereKey($observation->payment_financial_read_request_id)->lockForUpdate()->firstOrFail();
            if ($observation->status !== 'qualified_reported') {
                $this->reject();
            }
            if (! in_array($observation->classification, ['settled', 'refunded'], true)) {
                continue;
            }
            $kind = $observation->classification === 'settled' ? 'settlement' : 'refund';
            $amount = $observation->facts['amounts'][$kind === 'settlement' ? 'settled_minor' : 'refunded_minor'] ?? null;
            $original = $observation->facts['original_transaction_id'] ?? null;
            if (! is_int($amount) || $amount < 1 || $amount > 999999999999
                || ($kind === 'refund' && (! is_string($original) || ! preg_match('/\A[1-9][0-9]{0,31}\z/', $original)))) {
                $this->reject();
            }
            $facts = ['version' => 1, 'order_uuid' => $order->uuid, 'account' => $financial->canonical_account_key,
                'environment' => $financial->environment, 'entity_key' => $request->entity_key, 'currency' => $financial->currency,
                'kind' => $kind, 'amount_minor' => $amount,
                'parent_entity_key' => $kind === 'refund' ? $this->ledger->fingerprint(['association_transaction_v1' => $original]) : null];
            $key = $request->payment_association_scope_id.':'.$request->entity_key;
            if (isset($result[$key])) {
                $this->reject();
            }
            $result[$key] = ['scope_id' => $request->payment_association_scope_id, 'entity_key' => $request->entity_key,
                'kind' => $kind, 'amount_minor' => $amount, 'currency' => $financial->currency,
                'parent_entity_key' => $facts['parent_entity_key'], 'observation_id' => $observation->id,
                'economic_fingerprint' => $this->ledger->fingerprint($facts), 'facts' => $facts];
        }
        uasort($result, fn ($a, $b) => ($a['kind'] === 'settlement' ? 0 : 1) <=> ($b['kind'] === 'settlement' ? 0 : 1));

        return $result;
    }

    public function assertJournal(PaymentAccountingJournal $journal, array $candidate, Order $order): void
    {
        if ($journal->order_id !== $order->id || $journal->order_uuid !== $order->uuid
            || $journal->kind !== $candidate['kind'] || $journal->amount_minor !== $candidate['amount_minor']
            || $journal->currency !== $candidate['currency'] || ! hash_equals($journal->economic_fingerprint, $candidate['economic_fingerprint'])) {
            $this->reject();
        }
        $lines = PaymentAccountingLine::where('payment_accounting_journal_id', $journal->id)->lockForUpdate()->get();
        $debit = $journal->kind === 'settlement' ? 'gateway_clearing' : 'customer_payment_control';
        $credit = $journal->kind === 'settlement' ? 'customer_payment_control' : 'gateway_clearing';
        if ($lines->count() !== 2 || $lines->where('account', $debit)->where('debit_minor', $journal->amount_minor)->where('credit_minor', 0)->count() !== 1
            || $lines->where('account', $credit)->where('credit_minor', $journal->amount_minor)->where('debit_minor', 0)->count() !== 1) {
            $this->reject();
        }
    }

    public function reject(): never
    {
        throw ValidationException::withMessages(['accounting' => 'Current financial evidence or immutable accounting identity is not qualified.']);
    }
}
