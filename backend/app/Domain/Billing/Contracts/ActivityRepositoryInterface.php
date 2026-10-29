<?php

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Entities\ActivityTask;

interface ActivityRepositoryInterface
{
    public function find(string $type, string $id, ?int $userId = null, bool $lock = false): ?ActivityTask;

    /** @return iterable<ActivityTask> */
    public function active(string $type): iterable;

    public function stop(ActivityTask $task, string $reason): ActivityTask;
}
