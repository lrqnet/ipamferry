<?php

namespace App\Http\Middleware;

use App\Enums\SupportedLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = SupportedLocale::tryFrom((string) $request->user()?->locale)
            ?? SupportedLocale::tryFrom((string) $request->cookie('ipamferry_locale'))
            ?? SupportedLocale::English;

        App::setLocale($locale->value);

        return $next($request);
    }
}
