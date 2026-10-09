<?php

namespace App\Infrastructure\Privacy;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Privacy\Contracts\PrivacyRepositoryInterface;
use Closure;
use Illuminate\Support\Facades\Cache;

final class LaravelPrivacyCoordinator implements PrivacyCoordinatorInterface
{
    private array $held = [];

    public function __construct(private readonly PrivacyRepositoryInterface $repository) {}

    public function exclusive(string $type, string $id, Closure $operation): mixed
    {
        $key = 'privacy:project:'.$type.':'.hash('sha256', $id);
        if (isset($this->held[$key])) {
            return $operation();
        }

        return Cache::lock($key, 8000)->block(10, function () use ($key, $operation): mixed {
            $this->held[$key] = true;
            try {
                return $operation();
            } finally {
                unset($this->held[$key]);
            }
        });
    }

    public function projectDeleted(string $type, string $id): bool
    {
        return $this->repository->projectDeleted($type, $id);
    }

    public function sourceDeleted(string $path): bool
    {
        return $this->repository->sourceDeleted($path);
    }

    public function sourceDeletionPending(string $path): bool
    {
        return $this->repository->sourceDeletionPending($path);
    }

    public function generatedDeleted(string $type, string $id, string $category): bool
    {
        return $this->repository->generatedDeleted($type, $id, $category);
    }
}
