<?php

namespace App\Http\Responses\Privacy;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PrivacyResponse extends ApiResponse
{
    public function data(array $record): array
    {
        return array_intersect_key($record, array_flip(['id', 'resourceType', 'resourceId', 'scope', 'category',
            'status', 'failureReason', 'createdAt', 'completedAt']));
    }

    public function deletions(array $records): JsonResponse
    {
        return $this->json(['data' => array_map($this->data(...), $records)]);
    }
}
