<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\DubbingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(DubbingFactory::class)]
class Dubbing extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    protected function casts(): array
    {
        return ['duration_ms' => 'integer', 'submission_started_at' => 'datetime', 'provider_completed_at' => 'datetime', 'provider_options' => 'array', 'subtitles_enabled' => 'boolean',
            'source_subtitle_segments' => 'array', 'translated_subtitle_segments' => 'array', 'source_deleted_at' => 'immutable_datetime', 'privacy_deleted_files' => 'array', 'file_generated_at' => 'array'];
    }
}
