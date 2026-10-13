<?php

namespace App\Infrastructure\Analytics;

use App\Jobs\ResolveSignInLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class CustomerSignInRecorder
{
    public function record(User $user, string $method): void
    {
        if (! request()->is('api/v1/auth/login', 'api/v1/auth/google/callback') || $user->hasAnyRole(['admin', 'super_admin'])) {
            return;
        }
        try {
            $ip = request()->ip();
            $known = $ip !== null && $ip === $user->signup_ip ? $user->signup_location : null;
            $id = (string) Str::ulid();
            DB::transaction(function () use ($user, $method, $known, $id, $ip): void {
                DB::table('customer_sign_ins')->insert(['id' => $id, 'user_id' => $user->id,
                    'method' => $method === 'google' ? 'google' : 'password', 'country' => $known['country'] ?? null,
                    'country_code' => $known['countryCode'] ?? null, 'created_at' => now()]);
                if ($known === null && config('services.signup_location.enabled')
                    && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    ResolveSignInLocation::dispatch($id, $ip)->afterCommit();
                }
            });
        } catch (Throwable $exception) {
            Log::warning('Customer sign-in analytics could not be recorded.', ['user_id' => $user->id, 'exception_type' => $exception::class]);
        }
    }
}
