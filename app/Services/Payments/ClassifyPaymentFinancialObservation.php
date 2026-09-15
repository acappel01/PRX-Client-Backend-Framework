<?php

namespace App\Services\Payments;

use App\Data\Payments\AuthorizeNetTransactionRead;

/** Amounts are current reported state, not incremental ledger entries or bank balances. */
class ClassifyPaymentFinancialObservation
{
    public function classify(array $scope, AuthorizeNetTransactionRead $read, array $policy): array
    {
        $amounts = ['authorized_minor' => 0, 'captured_pending_minor' => 0, 'settled_minor' => 0, 'refund_pending_minor' => 0, 'refunded_minor' => 0, 'voided_minor' => 0];
        $status = 'qualified_reported';
        $classification = null;
        $expected = $scope['expected_amount'];
        $type = $read->transaction_type;
        if (! $policy['currency_qualified']) {
            $status = 'currency_unqualified';
        } elseif (in_array($read->transaction_status, ['returnedItem', 'chargeback', 'chargebackReversal'], true)) {
            $status = 'reversal_unqualified';
        } elseif ($read->transaction_status === 'authorizedPendingCapture' && $type === 'authOnlyTransaction' && $scope['capture_amount'] === null && ! in_array('voided', $scope['retained_statuses'], true) && $read->authorized_amount_minor === $scope['entity']['authorized_amount_minor']) {
            $classification = 'authorized';
            $amounts['authorized_minor'] = $read->authorized_amount_minor;
        } elseif (in_array($read->transaction_status, ['capturedPendingSettlement', 'settledSuccessfully'], true) && in_array($type, ['authOnlyTransaction', 'authCaptureTransaction', 'priorAuthCaptureTransaction'], true)) {
            if (($type === 'authOnlyTransaction' && $scope['capture_amount'] === null) || $read->settlement_amount_minor !== $expected || $read->authorized_amount_minor !== $scope['entity']['authorized_amount_minor']) {
                $status = 'amount_or_actor_unqualified';
            } else {
                $classification = $read->transaction_status === 'settledSuccessfully' ? 'settled' : 'captured_pending';
                $amounts[$classification.'_minor'] = $expected;
            }
        } elseif (in_array($read->transaction_status, ['refundPendingSettlement', 'refundSettledSuccessfully'], true) && $type === 'refundTransaction') {
            if ($read->settlement_amount_minor !== $expected) {
                $status = 'amount_or_actor_unqualified';
            } else {
                $classification = $read->transaction_status === 'refundSettledSuccessfully' ? 'refunded' : 'refund_pending';
                $amounts[$classification.'_minor'] = $expected;
            }
        } elseif ($read->transaction_status === 'voided'
            && in_array($read->authorized_amount_minor, [0, $scope['entity']['authorized_amount_minor']], true)
            && in_array($read->settlement_amount_minor, [0, $expected], true)) {
            $classification = 'voided';
            $amounts['voided_minor'] = $expected;
        } elseif ($read->transaction_status === 'expired' && $type === 'authOnlyTransaction' && $scope['capture_amount'] === null
            && in_array($read->authorized_amount_minor, [0, $scope['entity']['authorized_amount_minor']], true)
            && in_array($read->settlement_amount_minor, [0, $expected], true)) {
            $classification = 'expired';
        } else {
            $status = 'unsupported_status';
        }

        $terminal = ['settledSuccessfully' => 'settled', 'refundSettledSuccessfully' => 'refunded', 'voided' => 'voided', 'expired' => 'expired'];
        if ($status === 'qualified_reported') {
            foreach ($scope['retained_statuses'] as $retained) {
                if (isset($terminal[$retained]) && $terminal[$retained] !== $classification) {
                    $status = 'transition_conflict';
                }
            }
        }

        return ['status' => $status, 'classification' => $classification, 'amounts' => $status === 'qualified_reported' ? $amounts : []];
    }
}
