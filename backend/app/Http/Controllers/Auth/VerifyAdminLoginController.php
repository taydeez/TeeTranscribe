<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Admin\TwoFactor\Services\AdminTwoFactorService;
use App\Http\Requests\Auth\VerifyAdminLoginRequest;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class VerifyAdminLoginController
{
    public function __invoke(VerifyAdminLoginRequest $request, AdminTwoFactorService $service, AuthResponse $response): JsonResponse
    {
        $data = $request->validated();
        try {
            return $response->adminToken($service->verify($data['email'], $data['code']));
        } catch (RuntimeException $exception) {
            return $response->json($response->invalidAdminCode($exception->getMessage()), 422);
        }
    }
}
