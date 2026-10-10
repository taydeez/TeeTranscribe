<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Admin\Account\Services\AdminAccountService;
use App\Http\Requests\Admin\Auth\ChangeInitialPasswordRequest;
use App\Http\Responses\Admin\AdminAccountResponse;
use Illuminate\Http\JsonResponse;

final class ChangeInitialPasswordController
{
    public function __invoke(ChangeInitialPasswordRequest $request, AdminAccountService $service, AdminAccountResponse $response): JsonResponse
    {
        $service->changeInitialPassword((int) $request->user()->getAuthIdentifier(), $request->validated('current_password'), $request->validated('password'));

        return $response->changedPassword();
    }
}
