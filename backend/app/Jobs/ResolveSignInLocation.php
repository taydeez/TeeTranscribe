<?php

namespace App\Jobs;

use App\Infrastructure\Observability\ObserveJob;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ResolveSignInLocation implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $signInId, private string $ip)
    {
        $this->through([new ObserveJob]);
        $this->onConnection('redis')->onQueue('default');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        if (! config('services.signup_location.enabled') || ! filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || ! DB::table('customer_sign_ins')->where('id', $this->signInId)->whereNull('country')->exists()) {
            return;
        }
        $data = Http::acceptJson()->timeout(10)->get('https://ipapi.co/'.rawurlencode($this->ip).'/json/', array_filter([
            'key' => config('services.signup_location.key'),
        ]))->throw()->json();
        if (! is_array($data) || ($data['error'] ?? false) || ! is_string($data['country_name'] ?? null) || trim($data['country_name']) === '') {
            throw new RuntimeException('Sign-in location could not be resolved.');
        }
        $code = strtoupper((string) ($data['country_code'] ?? ''));
        DB::table('customer_sign_ins')->where('id', $this->signInId)->whereNull('country')->update([
            'country' => mb_substr(trim($data['country_name']), 0, 255), 'country_code' => preg_match('/^[A-Z]{2}$/D', $code) ? $code : null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Customer sign-in location lookup failed.', ['sign_in_id' => $this->signInId, 'exception_type' => $exception === null ? null : $exception::class]);
    }
}
