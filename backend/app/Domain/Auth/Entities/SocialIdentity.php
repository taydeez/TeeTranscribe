<?php

namespace App\Domain\Auth\Entities;

final readonly class SocialIdentity
{
    public function __construct(public string $providerId, public string $name, public string $email) {}
}
