<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserActive;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up', apiPrefix: 'api')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [AssignRequestId::class, SecurityHeaders::class]);
        $middleware->alias(['permission' => RequirePermission::class, 'active' => EnsureUserActive::class]);
        $trusted = env('TRUSTED_PROXIES');
        if ($trusted) {
            $middleware->trustProxies(at: $trusted === '*' ? '*' : explode(',', $trusted));
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        ApiExceptionHandler::register($exceptions);
    })
    ->create();
