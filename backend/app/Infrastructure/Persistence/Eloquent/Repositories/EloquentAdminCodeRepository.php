<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Admin\TwoFactor\Contracts\AdminCodeRepositoryInterface;
use App\Models\AdminLoginCode;

class EloquentAdminCodeRepository implements AdminCodeRepositoryInterface
{
    public function create(int $userId, string $codeHash): void
    {
        AdminLoginCode::query()->where('user_id', $userId)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        AdminLoginCode::query()->create(['user_id' => $userId, 'code_hash' => $codeHash, 'expires_at' => now()->addMinutes(10)]);
    }

    public function consumeValid(int $userId, string $codeHash): bool
    {
        $code = AdminLoginCode::query()->where('user_id', $userId)->where('code_hash', $codeHash)->whereNull('consumed_at')->where('expires_at', '>', now())->latest()->first();

        return $code ? $code->update(['consumed_at' => now()]) : false;
    }
}
