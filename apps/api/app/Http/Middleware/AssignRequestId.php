<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public const HEADER = 'X-Request-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = self::for($request);
        $startedAt = hrtime(true);
        Log::withContext([
            'correlation_id' => $requestId,
            'http_method' => $request->method(),
            'route' => $request->route()?->getName(),
        ]);
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        if ($response->getStatusCode() >= 400) {
            $context = [
                'event' => 'http.request.completed',
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ];
            $tenant = $request->attributes->get(TenantContext::class);
            if ($tenant instanceof TenantContext) {
                $context['user_id'] = $tenant->user->id;
                $context['organization_id'] = $tenant->organization->id;
            }

            Log::warning('http.request.completed', $context);
        }

        return $response;
    }

    public static function for(Request $request): string
    {
        $existing = $request->attributes->get('request_id');

        if (is_string($existing)) {
            return $existing;
        }

        $candidate = $request->headers->get(self::HEADER);
        $requestId = is_string($candidate) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $candidate) === 1
            ? $candidate
            : Str::uuid()->toString();

        $request->attributes->set('request_id', $requestId);

        return $requestId;
    }
}
