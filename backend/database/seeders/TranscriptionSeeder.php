<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Database\Seeder;

class TranscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Transcription::factory()->count(10)->create();
    }
}
