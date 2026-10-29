<?php

namespace App\Domain\Admin\Dashboard\Contracts;

interface DashboardRepositoryInterface
{
    public function report(string $from, string $until, string $environment): array;
}
