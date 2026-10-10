<?php

namespace App\Observers;

use App\Jobs\ResolveSignupLocation;
use App\Models\User;

final class SignupLocationObserver
{
    public function creating(User $user): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }
        $request = request();
        if ($request->is('api/v1/auth/register', 'api/v1/auth/google/callback')) {
            $ip = $request->ip();
            $user->signup_ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
        }
    }

    public function created(User $user): void
    {
        if (config('services.signup_location.enabled') && filter_var($user->signup_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            ResolveSignupLocation::dispatch($user->id)->afterCommit();
        }
    }
}
