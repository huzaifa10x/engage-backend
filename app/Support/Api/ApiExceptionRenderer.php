<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Support\Exceptions\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every API error as:
 *
 *   { "error": { "code": "plan_limit_reached", "message": "...", "details": {...}, "request_id": "..." } }
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $details, $headers] = self::map($e);

        $body = ['error' => array_filter([
            'code' => $code->value,
            'message' => $message,
            'details' => $details ?: null,
            'request_id' => Context::get('request_id'),
        ], fn ($v) => $v !== null)];

        if ($status >= 500 && config('app.debug')) {
            $body['error']['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        return new JsonResponse($body, $status, $headers);
    }

    /** @return array{int, ErrorCode, string, array<string, mixed>, array<string, string>} */
    private static function map(Throwable $e): array
    {
        return match (true) {
            $e instanceof DomainException => [$e->status(), $e->errorCode(), $e->getMessage(), $e->details(), []],
            $e instanceof ValidationException => [422, ErrorCode::ValidationFailed, 'The given data was invalid.', ['fields' => $e->errors()], []],
            $e instanceof AuthenticationException => [401, ErrorCode::Unauthenticated, 'Authentication required.', [], []],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, ErrorCode::Forbidden, 'You are not allowed to perform this action.', [], []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, ErrorCode::NotFound, 'Resource not found.', [], []],
            $e instanceof MethodNotAllowedHttpException => [405, ErrorCode::MethodNotAllowed, 'Method not allowed.', [], []],
            $e instanceof TokenMismatchException => [419, ErrorCode::CsrfMismatch, 'CSRF token mismatch.', [], []],
            $e instanceof ThrottleRequestsException => [429, ErrorCode::RateLimited, 'Too many requests.', [], self::stringHeaders($e->getHeaders())],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                $e->getStatusCode() === 503 ? ErrorCode::ServiceUnavailable : ($e->getStatusCode() >= 500 ? ErrorCode::ServerError : ErrorCode::Forbidden),
                $e->getMessage() ?: 'Request failed.',
                [],
                self::stringHeaders($e->getHeaders()),
            ],
            default => [500, ErrorCode::ServerError, 'An unexpected error occurred.', [], []],
        };
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        return array_map(fn ($v) => (string) $v, $headers);
    }
}
