<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Entities\ActivityTask;

final class ActivityDeadline
{
    public static function at(ActivityTask $task, array $rules): \DateTimeImmutable
    {
        $activity = $rules[$task->activity] ?? [];
        $rule = $activity[$task->provider] ?? $activity['default'] ?? ['base_minutes' => 30, 'duration_multiplier' => 2, 'max_minutes' => 1440];
        $minutes = min($rule['max_minutes'], $rule['base_minutes'] + (int) ceil(max(0, $task->durationSeconds) * $rule['duration_multiplier'] / 60));

        return $task->startedAt->modify('+'.max(1, $minutes).' minutes');
    }
}
