<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

$containerEnvironment = '/run/ipamferry-secrets/app.env';
if (is_readable($containerEnvironment)) {
    Dotenv::createImmutable(dirname($containerEnvironment), basename($containerEnvironment))->safeLoad();
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class, HandleInertiaRequests::class, AddLinkHeadersForPreloadedAssets::class]);
        $middleware->alias(['role' => EnsureRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'installation_token',
            'phpipam_token',
            'netbox_token',
        ]);
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->expectsJson() || $request->is('api/*'));
    })->create();
