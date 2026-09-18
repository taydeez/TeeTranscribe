<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestSession>
 */
class GuestSessionFactory extends Factory
{
    /** @var class-string<GuestSession> */
    protected $model = GuestSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', fake()->uuid()),
            'expires_at' => now()->addDay(),
        ];
    }
}
