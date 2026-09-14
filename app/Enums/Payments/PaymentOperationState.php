<?php

namespace App\Enums\Payments;

/** Passive bookkeeping only: neither state permits money execution. */
enum PaymentOperationState: string
{
    case Prepared = 'prepared';
    case Uncertain = 'uncertain';
}
