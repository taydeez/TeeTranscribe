<?php

namespace App\Infrastructure\Auth;

use App\Domain\Admin\Auth\Contracts\AdminSessionGatewayInterface;
use App\Domain\Admin\Auth\Services\AdminAccess;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;

final class AdminSessionGateway implements AdminSessionGatewayInterface
{
    public function recordActivity(int $userId, int $tokenId): string
    {
        $expires = now()->addMinutes(AdminAccess::IDLE_MINUTES);
        $updated = User::query()->findOrFail($userId)->tokens()
            ->whereKey($tokenId)->where('name', 'admin')->where('expires_at', '>', now())
            ->update(['expires_at' => $expires]);
        if (! $updated) {
            throw new AuthenticationException('Your administrator session has expired. Please sign in again.');
        }

        return $expires->toIso8601String();
    }
}
