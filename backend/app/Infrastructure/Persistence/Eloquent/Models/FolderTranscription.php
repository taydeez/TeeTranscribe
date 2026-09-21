<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class FolderTranscription extends Pivot
{
    use HasUlids;

    protected $table = 'folder_transcription';

    public $timestamps = false;
}
