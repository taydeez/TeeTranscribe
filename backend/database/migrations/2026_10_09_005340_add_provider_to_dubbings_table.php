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
            $table->string('provider', 30)->default('elevenlabs');
            $table->string('model', 50)->default('dubbing_v2');
            $table->json('provider_options')->nullable();
            $table->string('target_language', 100)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dubbings', function (Blueprint $table) {
            $table->dropColumn(['provider', 'model', 'provider_options']);
        });
    }
};
