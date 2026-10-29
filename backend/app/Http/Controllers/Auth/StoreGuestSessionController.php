<?php

namespace App\Http\Controllers\Auth;

use App\Http\Responses\Auth\AuthResponse;
use App\Infrastructure\Auth\GuestSessionService;
use Illuminate\Http\JsonResponse;

final class StoreGuestSessionController
{
    public function __invoke(GuestSessionService $service, AuthResponse $response): JsonResponse
    {
        return $response->guestSession($service->create());
    }
}
