<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class PrivacyDeletion extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'completed_at' => 'immutable_datetime'];
    }
}
