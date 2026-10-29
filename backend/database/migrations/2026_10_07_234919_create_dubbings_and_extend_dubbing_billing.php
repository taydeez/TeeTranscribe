<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dubbings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('name');
            $table->string('source_storage_path', 1024);
            $table->string('source_language', 20)->nullable();
            $table->string('target_language', 20);
            $table->unsignedBigInteger('duration_ms');
            $table->enum('status', ['pending', 'processing', 'complete', 'failed'])->default('pending');
            $table->string('provider_project_id')->nullable()->unique();
            $table->string('provider_language_id')->nullable();
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamp('provider_completed_at')->nullable();
            $table->string('audio_storage_path', 1024)->nullable();
            $table->string('video_storage_path', 1024)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        foreach (['billing_quotes', 'usage_charges'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignUlid('dubbing_id')->nullable()->unique()->constrained('dubbings');
            });
        }
    }

    public function down(): void
    {
        DB::table('usage_charges')->whereNotNull('dubbing_id')->delete();
        foreach (['usage_charges', 'billing_quotes'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropUnique(['dubbing_id']);
                $table->dropConstrainedForeignId('dubbing_id');
            });
        }
        Schema::dropIfExists('dubbings');
    }
};
