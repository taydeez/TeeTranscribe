<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcription_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('transcription_id')->constrained();
            $table->enum('format', ['txt', 'pdf']);
            $table->enum('status', ['pending', 'failed', 'completed'])->default('pending');
            $table->string('storage_path')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamps();

            $table->unique(['transcription_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcription_exports');
    }
};
