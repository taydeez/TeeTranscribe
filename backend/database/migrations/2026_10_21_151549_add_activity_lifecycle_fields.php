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
                $table->timestamp('activity_started_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->index(['status', 'activity_started_at']);
            });
        }
        Schema::table('transcriptions', fn (Blueprint $table) => $table->text('failure_reason')->nullable());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcriptions', fn (Blueprint $table) => $table->dropColumn('failure_reason'));
        foreach (['transcriptions', 'translations', 'dubbings', 'transcript_tools'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['status', 'activity_started_at']);
                $table->dropColumn(['activity_started_at', 'cancelled_at']);
            });
        }
    }
};
