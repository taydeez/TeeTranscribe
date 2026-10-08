<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Translation>
 */
class TranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Translation', 'source_text' => 'Hello world.',
            'source_language' => 'en', 'target_language' => 'fr', 'status' => 'pending',
        ];
    }
}
