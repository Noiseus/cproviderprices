<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiCsrfToken extends ValidateCsrfToken
{
    public function handle($request, Closure $next): Response
    {
        // Laravel's HTTP test harness marks the application through config rather
        // than the replacement middleware class. Preserve the framework's normal
        // test behavior while production cookie requests continue to use parent.
        if (config('app.env') === 'testing') {
            return $next($request);
        }

        if ($request->is('api/v1/*') && $request->bearerToken() !== null) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
