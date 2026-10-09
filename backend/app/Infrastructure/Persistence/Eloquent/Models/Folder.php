<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name'])]
class Folder extends Model
{
    use HasUlids;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Transcription, $this> */
    public function transcriptions(): BelongsToMany
    {
        return $this->belongsToMany(Transcription::class)
            ->using(FolderTranscription::class)
            ->withPivot('id');
    }

    /** @return HasMany<Translation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    /** @return HasMany<Dubbing, $this> */
    public function dubbings(): HasMany
    {
        return $this->hasMany(Dubbing::class);
    }
}
