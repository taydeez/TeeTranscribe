<?php

namespace App\Infrastructure\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ReadCache
{
    public function remember(string $key, int $seconds, Closure $load, bool $committedOnly = false): mixed
    {
        if (! config('read_cache.enabled', true) || ($committedOnly && DB::transactionLevel() > 0)) {
            return $load();
        }
        $generationKey = 'reads:v1:'.$key.':generation';
        try {
            $store = Cache::store(config('read_cache.store', 'redis'));
            $generation = $store->get($generationKey);
            if (! is_string($generation)) {
                $store->add($generationKey, (string) Str::uuid(), max(86400, $seconds * 2));
                $generation = $store->get($generationKey);
            }
            $valueKey = 'reads:v1:'.$key.':'.$generation;
            $cached = is_string($generation) ? $store->get($valueKey) : null;
            if (is_array($cached) && array_key_exists('value', $cached)) {
                return $cached['value'];
            }
        } catch (Throwable $exception) {
            $this->failed($key, $exception);

            return $load();
        }
        $value = $load();
        if (is_string($generation)) {
            try {
                $store->put($valueKey, ['value' => $value], max(1, $seconds));
            } catch (Throwable $exception) {
                $this->failed($key, $exception);
            }
        }

        return $value;
    }

    public function invalidate(string $key): void
    {
        if (! config('read_cache.enabled', true)) {
            return;
        }
        try {
            if (! Cache::store(config('read_cache.store', 'redis'))->put('reads:v1:'.$key.':generation', (string) Str::uuid(), 86400)) {
                throw new \RuntimeException('Cache invalidation was rejected.');
            }
        } catch (Throwable $exception) {
            $this->failed($key, $exception);
        }
    }

    private function failed(string $key, Throwable $exception): void
    {
        Log::warning('Read cache unavailable; using authoritative source.', ['key' => $key, 'exception_type' => $exception::class]);
    }
}
