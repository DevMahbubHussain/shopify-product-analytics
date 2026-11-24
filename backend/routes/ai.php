<?php

declare(strict_types=1);

use App\Mcp\Servers\AnalyticsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', AnalyticsServer::class)
    ->middleware(
        [
            'mcp.audit',
            'mcp.auth',
            'mcp.tenant',
            'mcp.consent',
            'mcp.entitlement',
            'throttle:mcp',
        ]
    );
