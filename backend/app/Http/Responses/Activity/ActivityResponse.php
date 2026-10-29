<?php

namespace App\Http\Responses\Activity;

use App\Domain\Billing\Entities\ActivityTask;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ActivityResponse extends ApiResponse
{
    public function stopped(ActivityTask $task): JsonResponse
    {
        return $this->json(['id' => $task->id, 'status' => $task->status, 'cancelledAt' => $task->cancelledAt, 'failureReason' => $task->failureReason]);
    }
}
