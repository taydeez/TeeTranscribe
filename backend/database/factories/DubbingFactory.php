<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Dubbing> */
class DubbingFactory extends Factory
{
    protected $model = Dubbing::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => 'Interview dub', 'source_storage_path' => 'audio/test.mp4',
            'source_language' => 'en', 'target_language' => 'fr', 'duration_ms' => 60000, 'status' => 'pending'];
    }
}
