<?php

namespace App\Enums\Payments;

enum PaymentUncertaintyReason: string
{
    case TransportTimeout = 'transport_timeout';
    case ConnectionLost = 'connection_lost';
    case UnverifiedResponse = 'unverified_response';
    case ReconciliationRequired = 'reconciliation_required';
}
