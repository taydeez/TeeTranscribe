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
        Schema::table('transcriptions_and_exports', function (Blueprint $table) {
            Schema::table('transcriptions', function (Blueprint $table): void {
                $table->unsignedInteger('export_revision')->default(0);
            });
            Schema::table('transcription_exports', function (Blueprint $table): void {
                $table->unsignedInteger('export_revision')->default(0);
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcriptions_and_exports', function (Blueprint $table) {
            Schema::table('transcription_exports', fn (Blueprint $table) => $table->dropColumn('export_revision'));
            Schema::table('transcriptions', fn (Blueprint $table) => $table->dropColumn('export_revision'));
        });
    }
};
