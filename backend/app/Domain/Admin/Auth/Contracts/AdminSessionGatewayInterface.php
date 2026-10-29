<?php

namespace App\Domain\Admin\Auth\Contracts;

interface AdminSessionGatewayInterface
{
    public function recordActivity(int $userId, int $tokenId): string;
}
