<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignUuid('guest_session_id')
                ->nullable()
                ->constrained('guest_sessions')
                ->nullOnDelete();

            $table->string('file_name');
            $table->string('name');
            $table->string('folder_name')->nullable();
            $table->longText('audio_path');
            $table->decimal('duration', 12, 3)->nullable()->comment('Audio duration in seconds');

            $table->string('provider_request_id')
                ->nullable()
                ->unique();

            $table->string('status')->default('pending');

            $table->longText('transcript')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['guest_session_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};
