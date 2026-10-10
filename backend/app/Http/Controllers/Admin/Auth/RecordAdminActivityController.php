<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Admin\Auth\Services\AdminSessionService;
use App\Http\Responses\Auth\AuthResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecordAdminActivityController
{
    public function __invoke(Request $request, AdminSessionService $sessions, AuthResponse $response): JsonResponse
    {
        return $response->adminActivity($sessions->recordActivity((int) $request->user()->getAuthIdentifier(), (int) $request->user()->currentAccessToken()->getKey()));
    }
}
