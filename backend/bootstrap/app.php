<?php

use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(append: [\App\Http\Middleware\RequestCorrelation::class]);
        $middleware->alias([
            'auth.token' => ApiTokenAuth::class,
            'perm' => RequirePermission::class,
            'workspace' => \App\Http\Middleware\WorkspaceContext::class,
            'idempotent' => \App\Http\Middleware\IdempotentCommand::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Business-rule violations (insufficient stock, invalid status change...) -> HTTP 422 with a readable message.
        $exceptions->render(function (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => true);
    })->create();
