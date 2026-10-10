<?php

namespace App\Http\Controllers\Admin\Role;

use App\Domain\Admin\Role\Services\AdminRoleService;
use App\Http\Responses\Admin\AdminRoleResponse;
use Illuminate\Http\JsonResponse;

final class ShowRoleController
{
    public function __invoke(int $role, AdminRoleService $service, AdminRoleResponse $response): JsonResponse
    {
        return $response->record($service->find($role));
    }
}
