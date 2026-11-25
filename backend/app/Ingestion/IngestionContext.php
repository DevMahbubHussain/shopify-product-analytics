<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Models\Shop;
use LogicException;

final class IngestionContext
{
    public ?Shop $shop = null;

    public ?IngestionResponse $response = null;

    /** @var list<object> */
    public array $events = [];

    /** @var list<string> */
    public array $outboxIds = [];

    public function __construct(
        public readonly string $shopDomain,
        public readonly object $payload,
    ) {}

    public function shop(): Shop
    {
        return $this->shop ?? throw new LogicException('Tenant was not resolved before a tenant-scoped pipe.');
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function payload(string $class): object
    {
        return $this->payload instanceof $class
            ? $this->payload
            : throw new LogicException("Pipe expected payload {$class}, got ".$this->payload::class.'.');
    }

    public function response(): IngestionResponse
    {
        return $this->response ?? throw new LogicException('Ingestion pipeline produced no response.');
    }
}
