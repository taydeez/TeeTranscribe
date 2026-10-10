<?php

namespace App\Domain\Admin\TwoFactor\Contracts;

interface AdminCodeMailerInterface
{
    public function send(string $email, string $code): void;
}
