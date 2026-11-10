<?php

declare(strict_types=1);

use App\Http\Middleware\BasicAuth;
use App\Http\Middleware\ShowLandingToGuests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a load balancer or reverse proxy: trust its
        // X-Forwarded-* headers so URLs and redirects use https.
        $middleware->trustProxies(at: '*');

        // Covers every web route, including Livewire updates and Horizon.
        $middleware->web(append: [BasicAuth::class]);

        // Must run before auth, which would otherwise send guests on / to the login page.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ShowLandingToGuests::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
