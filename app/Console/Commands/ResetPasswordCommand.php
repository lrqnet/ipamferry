<?php

namespace App\Console\Commands;

use App\Domain\Security\PasswordPolicy;
use App\Enums\UserRole;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ResetPasswordCommand extends Command
{
    protected $signature = 'ipamferry:reset-password {email? : Exact email address of the local account to reset}';

    protected $description = 'Interactively reset a local account password and invalidate its sessions.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('Password recovery requires an interactive terminal.');

            return self::FAILURE;
        }

        $user = $this->targetUser();
        if (! $user instanceof User) {
            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->components->error('Account is inactive and cannot be recovered.');

            return self::FAILURE;
        }

        if (! $this->confirm("Reset password for {$user->email}?", false)) {
            $this->components->warn('Password reset cancelled.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password');
        $confirmation = (string) $this->secret('Confirm new password');

        if (! hash_equals($password, $confirmation)) {
            $this->components->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $password): void {
            User::query()->whereKey($user->id)->update([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'updated_at' => now(),
            ]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            SecurityEvent::query()->create([
                'user_id' => $user->id,
                'kind' => 'password.reset',
                'origin' => 'cli',
                'context' => [],
            ]);
        });

        $this->components->info('Password reset completed. All active sessions were invalidated.');

        return self::SUCCESS;
    }

    private function targetUser(): ?User
    {
        $email = $this->argument('email');
        if (! is_string($email) || $email === '') {
            $owners = User::query()
                ->where('role', UserRole::Owner)
                ->where('is_active', true)
                ->limit(2)
                ->get();

            if ($owners->count() !== 1) {
                $this->components->error('Provide the account email when there is not exactly one active owner.');

                return null;
            }

            return $owners->first();
        }

        $validator = Validator::make(['email' => $email], [
            'email' => ['bail', 'required', 'string', 'max:254', 'not_regex:/\\s/u', 'email:rfc,spoof'],
        ]);
        if ($validator->fails()) {
            $this->components->error('Provide a valid account email address.');

            return null;
        }

        $user = User::query()->where('email', mb_strtolower($email))->first();
        if (! $user instanceof User) {
            $this->components->error('Account not found.');

            return null;
        }

        return $user;
    }
}
