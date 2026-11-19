<?php

declare(strict_types=1);

namespace App\Ingestion\Exceptions;

final class SyncRunAlreadyInProgress extends IngestionException
{
    public function __construct()
    {
        parent::__construct('A sync run is already in progress for this shop.');
    }

    public function status(): int
    {
        return 409;
    }
}
