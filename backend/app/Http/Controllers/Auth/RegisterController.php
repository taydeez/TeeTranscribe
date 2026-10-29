<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\AuthenticationService;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;

final class RegisterController
{
    public function __invoke(RegisterRequest $request, AuthenticationService $service, AuthResponse $response): JsonResponse
    {
        $data = $request->validated();

        return $response->json($service->register($data['name'], $data['email'], $data['password']), 201);
    }
}
