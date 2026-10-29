<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\AccountSecurityService;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResendVerificationController
{
    public function __invoke(Request $request, AccountSecurityService $service, AuthResponse $response): JsonResponse
    {
        $service->sendVerification((int) $request->user()->getAuthIdentifier());

        return $response->json($response->verificationSent());
    }
}
