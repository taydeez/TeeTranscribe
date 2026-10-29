<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\ActivityRepositoryInterface;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Entities\ActivityTask;
use App\Domain\Billing\Exceptions\BillingException;

final readonly class ActivityLifecycleService
{
    public function __construct(
        private ActivityRepositoryInterface $tasks,
        private BillingRepositoryInterface $billing,
        private BillingConfigurationInterface $settings,
        private CreditService $credits,
    ) {}

    public function cancel(string $type, string $id, int $userId): ActivityTask
    {
        return $this->billing->transaction(function () use ($type, $id, $userId) {
            $task = $this->tasks->find($type, $id, $userId, lock: true)
                ?? throw new BillingException('Task not found.', 404);

            return $task->active() ? $this->stop($task, 'cancelled') : $task;
        });
    }

    public function recover(string $type, string $id, \DateTimeImmutable $now): bool
    {
        return $this->billing->transaction(function () use ($type, $id, $now): bool {
            $task = $this->tasks->find($type, $id, lock: true);
            if ($task === null || ! $task->active() || ActivityDeadline::at($task, $this->settings->current()['task_deadlines']) > $now) {
                return false;
            }
            $this->stop($task, 'timeout');

            return true;
        });
    }

    private function stop(ActivityTask $task, string $reason): ActivityTask
    {
        $stopped = $this->tasks->stop($task, $reason);
        match ($task->type) {
            'transcription' => $this->credits->release($task->id),
            'translation' => $this->credits->releaseTranslation($task->id),
            'dubbing' => $this->credits->releaseDubbing($task->id),
            'transcript_tool' => $this->credits->releaseTool($task->id),
        };

        return $stopped;
    }
}
