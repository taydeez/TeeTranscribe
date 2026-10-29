<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Admin\TwoFactor\Contracts\AdminCodeRepositoryInterface;
use App\Models\AdminLoginCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EloquentAdminCodeRepository implements AdminCodeRepositoryInterface
{
    public function create(int $userId, string $codeHash): void
    {
        DB::transaction(function () use ($userId, $codeHash): void {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            AdminLoginCode::query()->where('user_id', $userId)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            AdminLoginCode::query()->create(['user_id' => $userId, 'code_hash' => $codeHash, 'expires_at' => now()->addMinutes(10)]);
        });
    }

    public function consumeValid(int $userId, string $codeHash): bool
    {
        return DB::transaction(function () use ($userId, $codeHash): bool {
            $code = AdminLoginCode::query()->where('user_id', $userId)->whereNull('consumed_at')
                ->where('expires_at', '>', now())->latest('id')->lockForUpdate()->first();
            if (! $code || $code->attempts >= 5) {
                return false;
            }
            $valid = hash_equals($code->code_hash, $codeHash);
            $code->attempts++;
            if ($valid || $code->attempts >= 5) {
                $code->consumed_at = now();
            }
            $code->save();

            return $valid;
        });
    }
}
