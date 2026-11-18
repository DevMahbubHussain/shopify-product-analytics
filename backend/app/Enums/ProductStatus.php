<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductStatus: string
{
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
    case Draft = 'DRAFT';
    case Unlisted = 'UNLISTED';
}
