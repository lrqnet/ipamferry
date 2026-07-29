<?php

namespace App\Domain\Security;

use Illuminate\Validation\Rules\Password;

final class PasswordPolicy
{
    public static function rules(): array
    {
        return ['bail', 'required', 'string', 'max:128', Password::min(14)->mixedCase()->numbers()->symbols()];
    }
}
