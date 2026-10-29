<?php

namespace App\Http\Responses\Admin;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AdminRoleResponse extends ApiResponse
{
    public function record(array $role, int $status = 200): JsonResponse
    {
        return $this->json(['data' => $role], $status);
    }

    public function permissions(array $permissions): JsonResponse
    {
        return $this->json(['data' => $permissions]);
    }
}
