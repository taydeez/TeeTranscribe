<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ResolveSignupLocation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        if (! config('services.signup_location.enabled') || $user === null || $user->signup_location !== null
            || ! filter_var($user->signup_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return;
        }
        $response = Http::acceptJson()->timeout(10)->get('https://ipapi.co/'.rawurlencode($user->signup_ip).'/json/', array_filter([
            'key' => config('services.signup_location.key'),
        ]))->throw()->json();
        if (! is_array($response) || ($response['error'] ?? false) || ! is_string($response['country_name'] ?? null)) {
            throw new RuntimeException('Signup location could not be resolved.');
        }
        $user->forceFill(['signup_location' => [
            'country' => mb_substr($response['country_name'], 0, 255),
            'countryCode' => mb_substr((string) ($response['country_code'] ?? ''), 0, 2),
            'region' => mb_substr((string) ($response['region'] ?? ''), 0, 255),
            'city' => mb_substr((string) ($response['city'] ?? ''), 0, 255),
            'source' => 'ipapi', 'resolvedAt' => now()->toIso8601String(),
        ]])->save();
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Signup location lookup failed.', ['user_id' => $this->userId, 'exception_type' => $exception ? $exception::class : null]);
    }
}
