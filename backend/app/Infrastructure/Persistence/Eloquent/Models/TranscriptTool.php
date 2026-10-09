<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\TranscriptToolFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(TranscriptToolFactory::class)]
class TranscriptTool extends Model
{
    /** @use HasFactory<TranscriptToolFactory> */
    use HasFactory;

    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_segments' => 'array', 'result' => 'array', 'progress' => 'array'];
    }

    /** @return BelongsTo<Transcription, $this> */
    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }
}
