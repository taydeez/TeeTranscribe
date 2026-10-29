<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Contracts\AccountSecurityGatewayInterface;
use App\Models\AdminLoginCode;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class LaravelAccountSecurityGateway implements AccountSecurityGatewayInterface
{
    public function updateName(int $userId, string $name): void
    {
        User::query()->findOrFail($userId)->update(['name' => $name]);
    }

    public function changePassword(int $userId, string $currentPassword, string $password): bool
    {
        return DB::transaction(function () use ($userId, $currentPassword, $password): bool {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            if (! Hash::check($currentPassword, $user->password)) {
                return false;
            }
            $this->replacePassword($user, $password);
            Password::deleteToken($user);

            return true;
        });
    }

    public function requestPasswordReset(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    public function resetPassword(string $email, string $token, string $password): bool
    {
        return DB::transaction(function () use ($email, $token, $password): bool {
            if (User::query()->where('email', $email)->lockForUpdate()->first() === null) {
                return false;
            }
            $status = Password::reset(compact('email', 'token', 'password'), function (User $user, string $password): void {
                $this->replacePassword($user, $password);
            });

            return $status === Password::PasswordReset;
        });
    }

    private function replacePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();
        AdminLoginCode::query()->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        event(new PasswordReset($user));
    }

    public function sendVerification(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }

    public function verifyEmail(int $userId, string $hash): bool
    {
        return DB::transaction(function () use ($userId, $hash): bool {
            $user = User::query()->lockForUpdate()->find($userId);
            if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                return false;
            }
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                event(new Verified($user));
            }

            return true;
        });
    }
}
