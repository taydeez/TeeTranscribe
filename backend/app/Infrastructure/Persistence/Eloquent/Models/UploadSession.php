<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use App\Domain\Upload\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UploadSession extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer', 'part_size' => 'integer', 'user_id' => 'integer',
            'expires_at' => 'immutable_datetime', 'status' => UploadStatus::class,
            'source_deleted_at' => 'immutable_datetime', 'privacy_deleted_files' => 'array',
        ];
    }
}
