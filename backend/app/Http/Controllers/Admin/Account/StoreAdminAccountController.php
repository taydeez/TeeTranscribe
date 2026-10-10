<?php

namespace App\Http\Controllers\Admin\Account;

use App\Domain\Admin\Account\Services\AdminAccountService;
use App\Http\Requests\Admin\Account\StoreAdminAccountRequest;
use App\Http\Responses\Admin\AdminAccountResponse;
use Illuminate\Http\JsonResponse;

final class StoreAdminAccountController
{
    public function __invoke(StoreAdminAccountRequest $request, AdminAccountService $service, AdminAccountResponse $response): JsonResponse
    {
        return $response->record($service->create((int) $request->user()->getAuthIdentifier(), $request->validated()), 201);
    }
}
