<?php

namespace App\Http\Controllers\Admin\Role;

use App\Domain\Admin\Role\Services\AdminRoleService;
use App\Http\Requests\Admin\Role\SyncRolePermissionsRequest;
use App\Http\Responses\Admin\AdminRoleResponse;
use Illuminate\Http\JsonResponse;

final class SyncRolePermissionsController
{
    public function __invoke(SyncRolePermissionsRequest $request, int $role, AdminRoleService $service, AdminRoleResponse $response): JsonResponse
    {
        return $response->record($service->syncPermissions($role, $request->validated('permissions'), (int) $request->user()->getAuthIdentifier()));
    }
}
