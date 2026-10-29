<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\AccountSecurityService;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;

final class VerifyEmailController
{
    public function __invoke(int $id, string $hash, AccountSecurityService $service, AuthResponse $response): JsonResponse
    {
        if (! $service->verifyEmail($id, $hash)) {
            return $response->json($response->invalidVerificationLink(), 403);
        }

        return $response->json($response->emailVerified());
    }
}
