<?php

namespace App\Http\Controllers\Admin\Role;

use App\Domain\Admin\Role\Services\AdminRoleService;
use App\Http\Requests\Admin\Role\UpdateRoleRequest;
use App\Http\Responses\Admin\AdminRoleResponse;
use Illuminate\Http\JsonResponse;

final class UpdateRoleController
{
    public function __invoke(UpdateRoleRequest $request, int $role, AdminRoleService $service, AdminRoleResponse $response): JsonResponse
    {
        return $response->record($service->update($role, $request->validated('name')));
    }
}
