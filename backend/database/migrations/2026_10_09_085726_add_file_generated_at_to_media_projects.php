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
        foreach (['translations', 'dubbings'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->json('file_generated_at')->nullable());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['translations', 'dubbings'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('file_generated_at'));
        }
    }
};
