<?php

use App\Http\Middleware\AssignMasarRequestId;
use App\Http\Middleware\AuthenticateMasarIntegrationClient;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // The Masar integration receiver (CONTRACT §3.21). This application had
        // no API surface before it; the file registered here is the whole of it.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'masar.request-id' => AssignMasarRequestId::class,
            'masar.integration.client' => AuthenticateMasarIntegrationClient::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // §3.21.7 — a rate limit is one of the retryable answers, and Masar
        // reads `error.code` to classify it. Laravel's own 429 body carries no
        // such field, so the envelope is given the shape the contract promises
        // rather than left to the framework's default.
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if ($request->is('api/v1/integration/*') && $exception->getStatusCode() === 429) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'SERVER_ERROR', 'message' => 'Too many integration requests.'],
                    'request_id' => $request->attributes->get('request_id'),
                ], 429);
            }
        });
    })->create();
