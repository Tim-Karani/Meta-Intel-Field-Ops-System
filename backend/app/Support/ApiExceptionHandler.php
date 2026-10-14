<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ApiExceptionHandler
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->dontReport([DomainRuleException::class, ValidationException::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (!($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error('The given data was invalid.', 422, $e->errors(), 'validation_failed'),
                $e instanceof DomainRuleException => ApiResponse::error($e->getMessage(), $e->status, $e->details, $e->errorCode),
                $e instanceof AuthenticationException => ApiResponse::error('Authentication is required.', 401, [], 'unauthenticated'),
                $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error('You are not authorized to access this resource.', 403, [], 'forbidden'),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error('Resource not found.', 404, [], 'not_found'),
                $e instanceof ThrottleRequestsException => ApiResponse::error('Too many requests. Please slow down.', 429, [], 'throttled'),
                $e instanceof QueryException && str_starts_with((string) $e->getCode(), '23') => ApiResponse::error('This record is referenced by other data or conflicts with an existing record.', 409, [], 'integrity_conflict'),
                $e instanceof HttpExceptionInterface => ApiResponse::error($e->getMessage() ?: 'Request failed.', $e->getStatusCode()),
                default => self::serverError($e),
            };
        });
    }

    private static function serverError(Throwable $e)
    {
        report($e);
        $message = config('app.debug') ? $e->getMessage() : 'An unexpected error occurred. Quote the request ID when contacting support.';
        return ApiResponse::error($message, 500, [], 'server_error');
    }
}
