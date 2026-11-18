<?php

declare(strict_types=1);

use Illuminate\Support\Str;

it('echoes a valid inbound correlation id on the response', function (): void {
    $correlationId = (string) Str::uuid();

    $this->getJson('/api/v1/health', ['X-Correlation-ID' => $correlationId])
        ->assertHeader('X-Correlation-ID', $correlationId);
});

it('generates a correlation id when none is supplied', function (): void {
    $response = $this->getJson('/api/v1/health');

    expect(Str::isUuid((string) $response->headers->get('X-Correlation-ID')))->toBeTrue();
});

it('replaces a malformed inbound correlation id with a generated one', function (): void {
    $response = $this->getJson('/api/v1/health', ['X-Correlation-ID' => "bad\nvalue"]);

    $header = (string) $response->headers->get('X-Correlation-ID');

    expect($header)->not->toBe("bad\nvalue")
        ->and(Str::isUuid($header))->toBeTrue();
});
