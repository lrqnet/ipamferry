<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::loginView(fn () => inertia('Auth/Login'));
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', mb_strtolower((string) $request->email))->first();

            return $user && $user->is_active && Hash::check((string) $request->password, $user->password) ? $user : null;
        });
    }
}
