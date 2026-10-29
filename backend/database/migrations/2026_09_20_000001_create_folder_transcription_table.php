<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_transcription', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('folder_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('transcription_id')->constrained()->cascadeOnDelete();

            $table->unique(['folder_id', 'transcription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_transcription');
    }
};
