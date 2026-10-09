<?php

namespace App\Http\Controllers\Admin;

use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminUserController
{
    public function __invoke(Request $request, AuthResponse $response): JsonResponse
    {
        return $response->user($request, admin: true);
    }
}
