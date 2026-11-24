<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Jobs\RecordMcpAuditLogJob;
use App\Mcp\Support\McpPrincipal;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AuditMcpRequest
{
    /** Column sizes of mcp_audit_logs; client-supplied values are cut to fit so the insert never fails. */
    private const METHOD_MAX = 64;

    private const TARGET_MAX = 128;

    private const CLIENT_MAX = 100;

    private const PROTOCOL_MAX = 16;

    public function handle(Request $request, Closure $next): Response
    {
        if (config('mcp.enabled', true) === false) {
            return new JsonResponse(
                data: [
                    'error' => 'mcp_disabled',
                    'error_description' => 'The MCP interface is currently disabled.',
                ],
                status: Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $request->attributes->set('mcp_start_time', microtime(true));
        $request->attributes->set('mcp_correlation_id', $this->correlationId());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $principal = $request->attributes->get('mcp_principal');

        if (! $principal instanceof McpPrincipal) {
            return;
        }

        $startTime = (float) $request->attributes->get('mcp_start_time', microtime(true));
        $durationMs = (int) max(1, round((microtime(true) - $startTime) * 1000));

        $method = $this->clip($request->header('Mcp-Method') ?? $request->json('method'), self::METHOD_MAX) ?? 'unknown';
        $targetName = $this->clip(
            $request->header('Mcp-Name') ?? $request->json('params.name') ?? $request->json('params.uri'),
            self::TARGET_MAX,
        );
        $clientName = $this->clip($request->header('X-MCP-Client') ?? $request->userAgent(), self::CLIENT_MAX);
        $protocolVersion = $this->clip($request->header('MCP-Protocol-Version'), self::PROTOCOL_MAX);

        dispatch(
            new RecordMcpAuditLogJob(
                shopId: $principal->shopId,
                connectionType: $principal->connectionType,
                connectionId: $principal->connectionId,
                method: $method,
                targetName: $targetName,
                status: $this->status($response),
                durationMs: $durationMs,
                clientName: $clientName,
                protocolVersion: $protocolVersion,
                correlationId: (string) $request->attributes->get('mcp_correlation_id', $this->correlationId()),
                createdAt: CarbonImmutable::now(),
            )
        );
    }

    /** Uses the id already validated by AssignCorrelationId; never the raw client header. */
    private function correlationId(): string
    {
        $fromContext = Context::get('correlation_id');

        return is_string($fromContext) && Str::isUuid($fromContext) ? $fromContext : (string) Str::uuid();
    }

    private function status(Response $response): string
    {
        $code = $response->getStatusCode();

        if ($code === Response::HTTP_TOO_MANY_REQUESTS) {
            return 'rate_limited';
        }

        if ($code === Response::HTTP_UNAUTHORIZED || $code === Response::HTTP_FORBIDDEN) {
            return 'rejected';
        }

        $body = json_decode((string) $response->getContent(), true);

        if (is_array($body) && isset($body['error'])) {
            return 'protocol_error';
        }

        if ($code < 200 || $code >= 400) {
            return 'error';
        }

        return is_array($body) && ($body['result']['isError'] ?? false) === true ? 'tool_error' : 'success';
    }

    private function clip(mixed $value, int $max): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        return mb_substr((string) $value, 0, $max);
    }
}
