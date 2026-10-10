<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Admin\Auth\Services\AdminLoginService;
use App\Http\Requests\Admin\Auth\AdminLoginRequest;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;

final class LoginAdminController
{
    public function __invoke(AdminLoginRequest $request, AdminLoginService $service, AuthResponse $response): JsonResponse
    {
        $input = $request->validated();

        return $response->adminEmailChallenge($service->login($input['email'], $input['password']));
    }
}
