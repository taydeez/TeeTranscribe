<?php

namespace App\Http\Controllers\Admin\Account;

use App\Domain\Admin\Account\Services\AdminAccountService;
use App\Http\Requests\Admin\Account\ListAdminAccountRequest;
use App\Http\Responses\Admin\AdminAccountResponse;
use Illuminate\Http\JsonResponse;

final class ListAdminAccountController
{
    public function __invoke(ListAdminAccountRequest $request, AdminAccountService $service, AdminAccountResponse $response): JsonResponse
    {
        return $response->json($service->paginate((int) $request->validated('page', 1), (string) $request->validated('search', '')))->header('Cache-Control', 'private, no-store');
    }
}
