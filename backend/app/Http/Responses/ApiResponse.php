<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class ApiResponse
{
    public function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    public function noContent(): Response
    {
        return response()->noContent();
    }

    public function away(string $url): RedirectResponse
    {
        return redirect()->away($url);
    }
}
