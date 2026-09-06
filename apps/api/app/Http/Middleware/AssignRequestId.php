<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public const HEADER = 'X-Request-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = self::for($request);
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

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
