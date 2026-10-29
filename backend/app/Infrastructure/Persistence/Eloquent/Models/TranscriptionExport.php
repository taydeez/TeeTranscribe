<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\TranscriptionExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['variant', 'export_revision', 'transcription_id', 'format', 'status', 'storage_path', 'failure_reason', 'processing_started_at'])]
#[UseFactory(TranscriptionExportFactory::class)]
class TranscriptionExport extends Model
{
    /** @use HasFactory<TranscriptionExportFactory> */
    use HasFactory;

    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['export_revision' => 'integer', 'processing_started_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Transcription, $this> */
    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }
}
