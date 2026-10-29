<?php

namespace App\Console\Commands;

use App\Domain\Billing\Contracts\ActivityRepositoryInterface;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Billing\Services\ActivityDeadline;
use App\Domain\Billing\Services\ActivityLifecycleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('activities:recover {--limit=100 : Maximum stalled tasks per activity}')]
#[Description('Recover stalled tasks and free customer concurrency capacity')]
class RecoverStalledActivities extends Command
{
    public function handle(BillingConfigurationInterface $settings, ActivityRepositoryInterface $tasks, ActivityLifecycleService $lifecycle): int
    {
        $rules = $settings->current()['task_deadlines'];
        $now = now()->toDateTimeImmutable();
        $limit = max(1, min(1000, (int) $this->option('limit')));
        foreach (['transcription', 'translation', 'dubbing', 'transcript_tool'] as $type) {
            $recovered = 0;
            foreach ($tasks->active($type) as $task) {
                if (ActivityDeadline::at($task, $rules) > $now) {
                    continue;
                }
                try {
                    if ($lifecycle->recover($type, $task->id, $now)) {
                        $recovered++;
                        Log::warning('Task deadline exceeded.', ['activity' => $task->activity, 'provider' => $task->provider, 'resource_id' => $task->id, 'user_id' => $task->userId]);
                    }
                } catch (Throwable $exception) {
                    Log::warning('Stalled activity recovery deferred.', ['activity' => $type, 'resource_id' => $task->id, 'exception_type' => $exception::class]);
                }
                if ($recovered >= $limit) {
                    break;
                }
            }
        }

        return self::SUCCESS;
    }
}
