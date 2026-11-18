<?php

declare(strict_types=1);

namespace App\Ingestion\Exceptions;

final class UnknownShop extends IngestionException
{
    public function __construct()
    {
        parent::__construct('Unknown shop.');
    }

    public function status(): int
    {
        return 404;
    }
}
