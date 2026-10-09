<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\AccountSecurityService;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;

final class UpdateProfileController
{
    public function __invoke(UpdateProfileRequest $request, AccountSecurityService $service, AuthResponse $response): JsonResponse
    {
        $data = $request->validated();
        $service->updateName((int) $request->user()->getAuthIdentifier(), $data['name']);

        return $response->json($response->profileUpdated());
    }
}
