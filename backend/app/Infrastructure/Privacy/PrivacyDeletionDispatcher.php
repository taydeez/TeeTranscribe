<?php

namespace App\Infrastructure\Privacy;

use App\Jobs\ProcessPrivacyDeletion;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PrivacyDeletionDispatcher
{
    public function dispatch(array $record): void
    {
        if ($record['status'] !== 'pending' && $record['status'] !== 'failed') {
            return;
        }
        try {
            ProcessPrivacyDeletion::dispatch($record['id'])->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('Privacy cleanup dispatch deferred.', ['deletion_id' => $record['id'], 'exception_type' => $exception::class]);
        }
    }
}
