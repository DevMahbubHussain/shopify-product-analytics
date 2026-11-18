<?php

declare(strict_types=1);

namespace App\Ingestion\Exceptions;

use App\Enums\SyncRunStatus;

final class IllegalSyncTransition extends IngestionException
{
    public function __construct(public readonly SyncRunStatus $from, public readonly SyncRunStatus $to)
    {
        parent::__construct('Illegal sync status transition.');
    }

    public function status(): int
    {
        return 409;
    }

    /** @return array{from: string, to: string} */
    public function details(): array
    {
        return ['from' => $this->from->value, 'to' => $this->to->value];
    }
}
