<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\GuestSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(GuestSessionFactory::class)]
#[Fillable(['token_hash', 'expires_at'])]
#[Hidden(['token_hash'])]
class GuestSession extends Model
{
    /** @use HasFactory<GuestSessionFactory> */
    use HasFactory;

    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }

    /** @return HasMany<Transcription, $this> */
    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }
}
