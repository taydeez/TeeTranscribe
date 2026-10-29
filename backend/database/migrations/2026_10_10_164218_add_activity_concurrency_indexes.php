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
        foreach (['transcriptions', 'translations', 'dubbings', 'transcript_tools'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->index(['user_id', 'status']);
                $table->index(['status', 'updated_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['transcriptions', 'translations', 'dubbings', 'transcript_tools'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'status']);
                $table->dropIndex(['status', 'updated_at']);
            });
        }
    }
};
