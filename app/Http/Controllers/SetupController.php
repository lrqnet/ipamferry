<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Response;

class SetupController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        return User::query()->exists() ? to_route('login') : inertia('Setup');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(User::query()->exists(), 403);
        $data = $request->validate([
            'token' => ['bail', 'required', 'string', 'min:32', 'max:128', 'regex:/^[A-Za-z0-9+\\/_=-]+$/'],
            'name' => ['bail', 'required', 'string', 'min:2', 'max:120', 'not_regex:/^\\s|\\s$/u', 'not_regex:/\\s{2,}/u', "regex:/^[\\pL\\pM\\pN][\\pL\\pM\\pN .,'’-]*$/u"],
            'email' => ['bail', 'required', 'string', 'max:254', 'not_regex:/\\s/u', 'email:rfc,spoof'],
            'password' => ['bail', 'required', 'string', 'max:128', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()],
        ]);
        $lock = Cache::lock('ipamferry:installation-claim', 30);
        abort_unless($lock->get(), 409, 'Installation claim is already in progress.');

        try {
            abort_if(User::query()->exists(), 403);
            $token = trim((string) @file_get_contents('/run/ipamferry-secrets/installation_token'));
            abort_unless($token !== '' && hash_equals($token, $data['token']), 422, 'Invalid installation token.');
            $owner = DB::transaction(fn (): User => User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => Hash::make($data['password']),
                'role' => UserRole::Owner,
                'locale' => app()->getLocale(),
                'is_active' => true,
            ]));
            @unlink('/run/ipamferry-secrets/installation_token');
            Auth::login($owner);

            return to_route('dashboard')->with('success', 'Installation claimed.');
        } finally {
            $lock->release();
        }
    }
}
