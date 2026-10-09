<?php

namespace App\Jobs;

use App\Domain\Privacy\Services\ProcessPrivacyDeletion as DeletionProcessor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessPrivacyDeletion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public string $deletionId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function handle(DeletionProcessor $processor): void
    {
        try {
            $processor->handle($this->deletionId);
        } catch (LockTimeoutException) {
            $this->release(60);
        }
    }

    public function uniqueId(): string
    {
        return 'privacy-deletion:'.$this->deletionId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(?\Throwable $exception): void
    {
        Log::warning('Privacy deletion needs a retry.', ['deletion_id' => $this->deletionId,
            'exception_type' => $exception !== null ? $exception::class : null]);
    }
}
