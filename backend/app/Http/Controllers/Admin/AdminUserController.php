<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
