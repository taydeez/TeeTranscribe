<?php

namespace App\Infrastructure\Auth;

use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class GuestSessionService
{
    public function create(): string
    {
        $token = Str::random(64);
        $session = GuestSession::query()->create(['token_hash' => hash('sha256', $token), 'expires_at' => Carbon::now()->addDays(30)]);

        return $session->id;
    }
}
