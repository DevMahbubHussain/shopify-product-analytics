<?php

declare(strict_types=1);

namespace App\Ingestion\Exceptions;

final class InactiveShop extends IngestionException
{
    public function __construct()
    {
        parent::__construct('Shop is not active.');
    }

    public function status(): int
    {
        return 403;
    }
}
