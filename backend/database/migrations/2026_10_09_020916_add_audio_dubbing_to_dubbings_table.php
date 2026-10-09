<?php

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
        Schema::table('dubbings', function (Blueprint $table) {
            $table->enum('media_type', ['video', 'audio'])->default('video');
            $table->string('audio_preview_storage_path', 1024)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dubbings', function (Blueprint $table) {
            $table->dropColumn(['media_type', 'audio_preview_storage_path']);
        });
    }
};
