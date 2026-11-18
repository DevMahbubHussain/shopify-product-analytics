<?php

declare(strict_types=1);

namespace App\Ingestion;

use JsonSerializable;

interface IngestionResponse extends JsonSerializable
{
    public function status(): int;
}
