<?php

namespace App\Http\Controllers;

use App\Enums\SupportedLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Cookie;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(SupportedLocale::values())]]);
        $locale = SupportedLocale::from($data['locale']);

        if ($request->user()?->locale !== $locale->value) {
            $request->user()?->update(['locale' => $locale->value]);
        }

        return back()->withCookie(Cookie::create(
            name: 'ipamferry_locale', value: $locale->value, expire: now()->addYear(), path: '/',
            secure: $request->isSecure(), httpOnly: true, sameSite: Cookie::SAMESITE_LAX,
        ));
    }
}
