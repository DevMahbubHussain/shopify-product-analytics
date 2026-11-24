<?php

declare(strict_types=1);

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\AuditMcpRequest;
use App\Http\Middleware\AuthenticateMcp;
use App\Http\Middleware\EnforceMcpEntitlement;
use App\Http\Middleware\EnsureMcpConsent;
use App\Http\Middleware\ResolveMcpTenant;
use App\Http\Middleware\VerifyS2SSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        then: function (): void {
            include __DIR__.'/../routes/ai.php';
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(
        function (Middleware $middleware): void {
            $middleware->prepend(AssignCorrelationId::class);
            $middleware->throttleWithRedis();
            $middleware->prependToPriorityList(
                before: [ThrottleRequests::class, ThrottleRequestsWithRedis::class],
                prepend: VerifyS2SSignature::class,
            );
            $middleware->prependToPriorityList(
                before: [ThrottleRequests::class, ThrottleRequestsWithRedis::class],
                prepend: AuthenticateMcp::class,
            );

            $middleware->alias(
                [
                    's2s' => VerifyS2SSignature::class,
                    'mcp.audit' => AuditMcpRequest::class,
                    'mcp.auth' => AuthenticateMcp::class,
                    'mcp.tenant' => ResolveMcpTenant::class,
                    'mcp.consent' => EnsureMcpConsent::class,
                    'mcp.entitlement' => EnforceMcpEntitlement::class,
                ]
            );
        }
    )
    ->withExceptions(
        function (Exceptions $exceptions): void {
            $exceptions->shouldRenderJsonWhen(
                fn (Request $request): bool => $request->is('api/*') || $request->is('internal/*') || $request->is('mcp*') || $request->expectsJson(),
            );
        }
    )->create();
