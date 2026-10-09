<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\AccountSecurityService;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;

final class ResetPasswordController
{
    public function __invoke(ResetPasswordRequest $request, AccountSecurityService $service, AuthResponse $response): JsonResponse
    {
        $data = $request->validated();
        if (! $service->resetPassword($data['email'], $data['token'], $data['password'])) {
            return $response->json($response->invalidResetLink(), 422);
        }

        return $response->json($response->passwordReset());
    }
}
