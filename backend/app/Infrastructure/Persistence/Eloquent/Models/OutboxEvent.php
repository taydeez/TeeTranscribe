<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_key', 'event_type', 'aggregate_id', 'payload', 'published_at', 'attempts'])]
#[UseFactory(OutboxEventFactory::class)]
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory;

    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'published_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
