<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\TranslationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(TranslationFactory::class)]
class Translation extends Model
{
    use HasFactory;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_segments' => 'array', 'segments' => 'array', 'exports' => 'array', 'export_revision' => 'integer'];
    }
}
