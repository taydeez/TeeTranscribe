<?php

namespace App\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class GuestSessionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        $token = Str::random(64);
        $session = GuestSession::query()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        return response()->json(['id' => $session->id]);
    }
}
