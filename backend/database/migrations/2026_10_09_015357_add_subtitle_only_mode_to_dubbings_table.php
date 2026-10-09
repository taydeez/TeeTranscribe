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
            $table->enum('operation', ['dubbing', 'subtitles'])->default('dubbing');
            $table->jsonb('source_subtitle_segments')->nullable();
            $table->jsonb('translated_subtitle_segments')->nullable();
            $table->string('detected_source_language', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dubbings', function (Blueprint $table) {
            $table->dropColumn(['operation', 'source_subtitle_segments', 'translated_subtitle_segments', 'detected_source_language']);
        });
    }
};
