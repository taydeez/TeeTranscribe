<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\DubbingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(DubbingFactory::class)]
class Dubbing extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['duration_ms' => 'integer', 'submission_started_at' => 'datetime', 'provider_completed_at' => 'datetime'];
    }
}
