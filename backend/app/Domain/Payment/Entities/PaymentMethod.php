<?php

namespace App\Domain\Payment\Entities;

final readonly class PaymentMethod
{
    public function __construct(public string $id, public string $name, public string $code, public ?string $description, public bool $isActive, public int $sortOrder, public array $publicConfig) {}
}
