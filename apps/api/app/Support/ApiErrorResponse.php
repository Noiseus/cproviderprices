<?php

namespace App\Support;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiErrorResponse
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $status = match (true) {
            $exception instanceof ApiProblem => $exception->status,
            $exception instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $exception instanceof AuthorizationException => Response::HTTP_FORBIDDEN,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
        $code = self::codeFor($status);
        $message = $status >= Response::HTTP_INTERNAL_SERVER_ERROR
            ? 'An unexpected error occurred.'
            : (Response::$statusTexts[$status] ?? 'Request failed.');
        $requestId = AssignRequestId::for($request);
        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ];

        if ($exception instanceof ApiProblem) {
            $error['code'] = $exception->problemCode;
            $error['message'] = $exception->getMessage();

            if ($exception->details !== []) {
                $error['details'] = $exception->details;
            }
        }

        if ($exception instanceof ValidationException) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            $error['code'] = 'validation_failed';
            $error['message'] = 'The given data was invalid.';
            $error['details'] = ['fields' => $exception->errors()];
        }

        return response()
            ->json(['error' => $error], $status)
            ->header(AssignRequestId::HEADER, $requestId);
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'bad_request',
            Response::HTTP_UNAUTHORIZED => 'unauthorized',
            Response::HTTP_FORBIDDEN => 'forbidden',
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
            Response::HTTP_TOO_MANY_REQUESTS => 'too_many_requests',
            default => $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                ? 'internal_error'
                : 'http_error',
        };
    }
}
