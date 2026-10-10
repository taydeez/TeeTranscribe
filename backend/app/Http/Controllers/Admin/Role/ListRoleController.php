<?php

namespace App\Http\Controllers\Admin\Role;

use App\Domain\Admin\Role\Services\AdminRoleService;
use App\Http\Requests\Admin\Role\ListRoleRequest;
use App\Http\Responses\Admin\AdminRoleResponse;
use Illuminate\Http\JsonResponse;

final class ListRoleController
{
    public function __invoke(ListRoleRequest $request, AdminRoleService $service, AdminRoleResponse $response): JsonResponse
    {
        return $response->json($service->paginate((int) $request->validated('page', 1), (int) $request->validated('per_page', 20)));
    }
}
