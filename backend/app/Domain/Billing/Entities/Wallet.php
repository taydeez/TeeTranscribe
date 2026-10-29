<?php

namespace App\Domain\Billing\Entities;

final class Wallet
{
    public function __construct(
        public string $id,
        public int $userId,
        public int $available,
        public int $reserved,
    ) {}
}
