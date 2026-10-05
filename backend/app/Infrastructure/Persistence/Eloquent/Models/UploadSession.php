<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use App\Domain\Upload\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class UploadSession extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer', 'part_size' => 'integer', 'user_id' => 'integer',
            'expires_at' => 'immutable_datetime', 'status' => UploadStatus::class,
        ];
    }
}
