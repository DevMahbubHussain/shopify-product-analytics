<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncType: string
{
    case Initial = 'initial';
    case Incremental = 'incremental';
    case Reconciliation = 'reconciliation';
}
