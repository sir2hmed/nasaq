<?php

use App\Http\Middleware\AddCorrelationId;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\VerifyInternalServiceToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
        $middleware->api(prepend: [AddCorrelationId::class]);
        $middleware->alias([
            'service.token' => VerifyInternalServiceToken::class,
            'admin' => EnsureUserIsAdmin::class,
            'active' => EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'data' => null,
                'message' => 'The submitted data is invalid.',
                'errors' => $exception->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'data' => null,
                'message' => 'Authentication is required.',
                'errors' => null,
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'data' => null,
                'message' => 'You are not allowed to access this resource.',
                'errors' => null,
            ], 403);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            [$code, $message] = match ($status) {
                404 => ['not_found', 'The requested resource was not found.'],
                405 => ['method_not_allowed', 'This request method is not allowed.'],
                419 => ['session_expired', 'Your session has expired. Please sign in again.'],
                422 => ['unprocessable_request', $exception->getMessage() ?: 'This request cannot be completed with the current configuration.'],
                429 => ['rate_limited', 'Too many requests. Please wait and try again.'],
                default => ['request_failed', 'The request could not be completed.'],
            };

            return response()->json([
                'data' => null,
                'message' => $message,
                'errors' => [
                    'request' => [[
                        'code' => $code,
                        'status' => $status,
                        'correlation_id' => $request->attributes->get('correlation_id'),
                    ]],
                ],
            ], $status, $exception->getHeaders());
        });
    })->create();
