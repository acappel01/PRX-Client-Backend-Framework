<?php

namespace App\Enums\Payments;

enum PaymentOperationPurpose: string
{
    case Sale = 'sale';
    case Authorize = 'authorize';
    case Capture = 'capture';
    case Refund = 'refund';
    case Void = 'void';
}
