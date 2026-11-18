<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderFinancialStatus: string
{
    case Authorized = 'AUTHORIZED';
    case Expired = 'EXPIRED';
    case Paid = 'PAID';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Pending = 'PENDING';
    case Refunded = 'REFUNDED';
    case Voided = 'VOIDED';
}
