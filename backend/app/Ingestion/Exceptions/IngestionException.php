<?php

declare(strict_types=1);

namespace App\Ingestion\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

abstract class IngestionException extends RuntimeException
{
    abstract public function status(): int;

    /** @return array<string, mixed> */
    public function details(): array
    {
        return [];
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['error' => $this->getMessage(), ...$this->details()], $this->status());
    }
}
