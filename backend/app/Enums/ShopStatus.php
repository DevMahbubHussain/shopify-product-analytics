<?php

declare(strict_types=1);

namespace App\Enums;

enum ShopStatus: string
{
    case Active = 'active';
    case Uninstalled = 'uninstalled';
    case Suspended = 'suspended';
}
