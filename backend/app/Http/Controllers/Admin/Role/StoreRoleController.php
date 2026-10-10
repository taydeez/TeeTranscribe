<?php

namespace App\Http\Controllers\Admin\Role;

use App\Domain\Admin\Role\Services\AdminRoleService;
use App\Http\Requests\Admin\Role\StoreRoleRequest;
use App\Http\Responses\Admin\AdminRoleResponse;
use Illuminate\Http\JsonResponse;

final class StoreRoleController
{
    public function __invoke(StoreRoleRequest $request, AdminRoleService $service, AdminRoleResponse $response): JsonResponse
    {
        return $response->record($service->create($request->validated('name')), 201);
    }
}
