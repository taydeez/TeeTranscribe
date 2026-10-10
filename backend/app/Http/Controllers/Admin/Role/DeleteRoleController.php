<?php

namespace App\Http\Controllers\Admin\Role;

use App\Domain\Admin\Role\Services\AdminRoleService;
use App\Http\Responses\Admin\AdminRoleResponse;
use Illuminate\Http\Response;

final class DeleteRoleController
{
    public function __invoke(int $role, AdminRoleService $service, AdminRoleResponse $response): Response
    {
        $service->delete($role);

        return $response->noContent();
    }
}
