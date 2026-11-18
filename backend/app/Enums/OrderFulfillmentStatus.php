<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderFulfillmentStatus: string
{
    case Fulfilled = 'FULFILLED';
    case FulfillmentNotRequired = 'FULFILLMENT_NOT_REQUIRED';
    case InProgress = 'IN_PROGRESS';
    case OnHold = 'ON_HOLD';
    case Open = 'OPEN';
    case PartiallyFulfilled = 'PARTIALLY_FULFILLED';
    case PendingFulfillment = 'PENDING_FULFILLMENT';
    case RequestDeclined = 'REQUEST_DECLINED';
    case Restocked = 'RESTOCKED';
    case Scheduled = 'SCHEDULED';
    case Unfulfilled = 'UNFULFILLED';
}
