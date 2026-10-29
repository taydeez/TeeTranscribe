<?php

namespace App\Domain\Admin\Dashboard\Services;

use App\Domain\Admin\Dashboard\Contracts\DashboardRepositoryInterface;

final readonly class DashboardService
{
    public function __construct(private DashboardRepositoryInterface $repository) {}

    public function report(string $from, string $until, string $environment): array
    {
        return $this->repository->report($from, $until, $environment);
    }
}
