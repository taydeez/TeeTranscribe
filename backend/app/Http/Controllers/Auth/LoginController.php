<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Admin\TwoFactor\Services\AdminTwoFactorService;
use App\Domain\Auth\Services\AuthenticationService;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;

final class LoginController
{
    public function __invoke(LoginRequest $request, AuthenticationService $service, AdminTwoFactorService $twoFactor, AuthResponse $response): JsonResponse
    {
        $data = $request->validated();
        $result = $service->login($data['email'], $data['password']);
        if ($result['is_admin']) {
            $twoFactor->send($result['user']->id, $result['user']->email);

            return $response->json($response->adminChallenge($result));
        }

        return $response->json($result);
    }
}
