<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateWebOrMobile;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequirePlatformScope;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyApiCsrfToken;
use App\Support\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Throwable as BaseThrowable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [AssignRequestId::class, SecurityHeaders::class]);
        $middleware->web(replace: [ValidateCsrfToken::class => VerifyApiCsrfToken::class]);
        $middleware->trustHosts();
        $middleware->alias([
            'auth.api' => AuthenticateWebOrMobile::class,
            'active.user' => EnsureActiveUser::class,
            'platform.scope' => RequirePlatformScope::class,
            'tenant' => ResolveTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(
            fn (BaseThrowable $exception, Request $request) => ApiErrorResponse::render($exception, $request)
        );
    })->create();
