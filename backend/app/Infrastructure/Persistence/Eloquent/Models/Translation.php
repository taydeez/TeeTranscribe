<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\TranslationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(TranslationFactory::class)]
class Translation extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = [];

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    protected function casts(): array
    {
        return ['source_segments' => 'array', 'segments' => 'array', 'exports' => 'array', 'export_revision' => 'integer', 'source_deleted_at' => 'immutable_datetime', 'privacy_deleted_files' => 'array', 'file_generated_at' => 'array'];
    }
}
