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
            $table->boolean('subtitles_enabled')->default(false);
            $table->string('subtitle_style', 30)->nullable();
            $table->enum('subtitle_status', ['pending', 'processing', 'complete', 'failed'])->nullable();
            $table->string('subtitle_storage_path', 1024)->nullable();
            $table->string('captioned_video_storage_path', 1024)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dubbings', function (Blueprint $table) {
            $table->dropColumn(['subtitles_enabled', 'subtitle_style', 'subtitle_status', 'subtitle_storage_path', 'captioned_video_storage_path']);
        });
    }
};
