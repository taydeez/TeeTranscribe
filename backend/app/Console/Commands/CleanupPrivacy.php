<?php

namespace App\Console\Commands;

use App\Domain\Privacy\Contracts\PrivacyRepositoryInterface;
use App\Domain\Privacy\Services\PrivacyService;
use App\Jobs\ProcessPrivacyDeletion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('privacy:cleanup {--limit=100 : Maximum expired items and pending deletions per run}')]
#[Description('Queue owned files and projects whose user retention period has elapsed')]
class CleanupPrivacy extends Command
{
    public function handle(PrivacyService $privacy, PrivacyRepositoryInterface $repository): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $expired = $privacy->queueExpired($limit);
        $pending = $repository->pending($limit);
        foreach ($pending as $deletion) {
            ProcessPrivacyDeletion::dispatch($deletion['id'])->afterCommit();
        }
        $this->info("Queued {$expired} expired items and ".count($pending).' pending deletions.');

        return self::SUCCESS;
    }
}
