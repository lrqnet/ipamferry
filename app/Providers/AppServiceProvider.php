<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $identity = mb_strtolower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by(hash('sha256', $identity));
        });
        RateLimiter::for('migration-write', fn ($request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('migration-apply', fn ($request) => Limit::perMinute(120)->by((string) $request->user()?->id));
    }
}
