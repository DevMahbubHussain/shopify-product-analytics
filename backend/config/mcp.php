<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Server Enablement (Emergency Kill Switch)
    |--------------------------------------------------------------------------
    |
    | When disabled, all incoming MCP requests will be rejected immediately
    | with a 503 Service Unavailable response before processing.
    |
    */
    'enabled' => (bool) env('MCP_ENABLED', true),
];
