<?php

namespace App\Http\Controllers\Admin\Role;

use App\Domain\Admin\Role\Services\AdminRoleService;
use App\Http\Responses\Admin\AdminRoleResponse;
use Illuminate\Http\JsonResponse;

final class ListPermissionsController
{
    public function __invoke(AdminRoleService $service, AdminRoleResponse $response): JsonResponse
    {
        return $response->permissions($service->permissions());
    }
}
