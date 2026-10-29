<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\AccountSecurityService;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;

final class ForgotPasswordController
{
    public function __invoke(ForgotPasswordRequest $request, AccountSecurityService $service, AuthResponse $response): JsonResponse
    {
        $data = $request->validated();
        $service->requestPasswordReset($data['email']);

        return $response->json($response->resetLinkSent());
    }
}
