<?php

namespace App\Http\Controllers\Auth;

use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LogoutController
{
    public function __invoke(Request $request, AuthResponse $response): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return $response->json($response->loggedOut());
    }
}
