<?php

use App\Http\Controllers\HealthController;
use App\Http\Responses\ApiExceptionResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        then: static function (): void {
            Route::get('/health', [HealthController::class, 'show']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->remove([
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);
        $middleware->api(remove: SubstituteBindings::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*', 'logs', 'logs/*') || $request->expectsJson(),
        );
        $exceptions->respond(new ApiExceptionResponse);
    })->create();
