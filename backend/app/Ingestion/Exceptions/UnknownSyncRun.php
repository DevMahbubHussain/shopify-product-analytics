<?php

declare(strict_types=1);

namespace App\Ingestion\Exceptions;

final class UnknownSyncRun extends IngestionException
{
    public function __construct()
    {
        parent::__construct('Unknown sync run.');
    }

    public function status(): int
    {
        return 404;
    }
}
