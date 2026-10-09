<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\AccountSecurityService;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;

final class ChangePasswordController
{
    public function __invoke(ChangePasswordRequest $request, AccountSecurityService $service, AuthResponse $response): JsonResponse
    {
        $data = $request->validated();
        if (! $service->changePassword((int) $request->user()->getAuthIdentifier(), $data['current_password'], $data['password'])) {
            return $response->json($response->incorrectPassword(), 422);
        }

        return $response->json($response->passwordChanged());
    }
}
