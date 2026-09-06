<?php

use App\Http\Middleware\AssignRequestId;
use App\Support\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Throwable as BaseThrowable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [AssignRequestId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(
            fn (BaseThrowable $exception, Request $request) => ApiErrorResponse::render($exception, $request)
        );
    })->create();
