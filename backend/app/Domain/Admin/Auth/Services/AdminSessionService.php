<?php

namespace App\Domain\Admin\Auth\Services;

use App\Domain\Admin\Auth\Contracts\AdminSessionGatewayInterface;

final class AdminSessionService
{
    public function __construct(private readonly AdminSessionGatewayInterface $sessions) {}

    public function recordActivity(int $userId, int $tokenId): string
    {
        return $this->sessions->recordActivity($userId, $tokenId);
    }
}
