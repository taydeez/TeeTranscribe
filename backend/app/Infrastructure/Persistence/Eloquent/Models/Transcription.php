<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use App\Models\User;
use Database\Factories\TranscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'guest_session_id', 'audio_path', 'file_name', 'name', 'folder_name', 'duration', 'provider', 'provider_request_id', 'status', 'transcript'])]
#[UseFactory(TranscriptionFactory::class)]
class Transcription extends Model
{
    /** @use HasFactory<TranscriptionFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'duration' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<GuestSession, $this> */
    public function guestSession(): BelongsTo
    {
        return $this->belongsTo(GuestSession::class);
    }

    /** @return HasMany<TranscriptionExport, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(TranscriptionExport::class);
    }

    /** @return BelongsToMany<Folder, $this> */
    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class)
            ->using(FolderTranscription::class)
            ->withPivot('id');
    }
}
