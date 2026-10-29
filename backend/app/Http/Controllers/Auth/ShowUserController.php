<?php

namespace App\Http\Controllers\Auth;

use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowUserController
{
    public function __invoke(Request $request, AuthResponse $response): JsonResponse
    {
        return $response->user($request);
    }
}
