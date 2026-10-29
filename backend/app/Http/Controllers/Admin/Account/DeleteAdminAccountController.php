<?php

namespace App\Http\Controllers\Admin\Account;

use App\Domain\Admin\Account\Services\AdminAccountService;
use App\Http\Responses\Admin\AdminAccountResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeleteAdminAccountController
{
    public function __invoke(Request $request, int $account, AdminAccountService $service, AdminAccountResponse $response): Response
    {
        $service->delete((int) $request->user()->getAuthIdentifier(), $account);

        return $response->noContent();
    }
}
