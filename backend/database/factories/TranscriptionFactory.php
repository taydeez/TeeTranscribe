<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transcription>
 */
class TranscriptionFactory extends Factory
{
    /** @var class-string<Transcription> */
    protected $model = Transcription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'guest_session_id' => null,
            'audio_path' => 'audio/'.fake()->uuid().'.mp3',
            'file_name' => 'recording.mp3',
            'name' => 'Recording',
            'folder_name' => null,
            'duration' => null,
            'provider_request_id' => null,
            'status' => 'pending',
            'transcript' => null,
        ];
    }

    public function forGuestSession(?GuestSession $guestSession = null): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'guest_session_id' => $guestSession?->id ?? GuestSession::factory(),
        ]);
    }
}
