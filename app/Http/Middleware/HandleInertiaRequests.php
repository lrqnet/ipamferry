<?php

namespace App\Http\Middleware;

use App\Enums\SupportedLocale;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'name' => config('app.name'), 'locale' => app()->getLocale(), 'availableLocales' => SupportedLocale::options(), 'auth' => ['user' => $request->user()], 'flash' => ['success' => fn () => $request->session()->get('success'), 'error' => fn () => $request->session()->get('error')]];
    }
}
