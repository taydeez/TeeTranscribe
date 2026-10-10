<?php

namespace App\Http\Controllers\Admin\Account;

use App\Domain\Admin\Account\Services\AdminAccountService;
use App\Http\Requests\Admin\Account\UpdateAdminAccountRoleRequest;
use App\Http\Responses\Admin\AdminAccountResponse;
use Illuminate\Http\JsonResponse;

final class UpdateAdminAccountRoleController
{
    public function __invoke(UpdateAdminAccountRoleRequest $request, int $account, AdminAccountService $service, AdminAccountResponse $response): JsonResponse
    {
        return $response->record($service->updateRole((int) $request->user()->getAuthIdentifier(), $account, (int) $request->validated('role_id')));
    }
}
