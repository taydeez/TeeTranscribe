<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->foreignUlid('transcription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->longText('source_text');
            $table->longText('translated_text')->nullable();
            $table->string('source_language', 20)->nullable();
            $table->string('detected_language', 20)->nullable();
            $table->string('target_language', 20);
            $table->string('provider')->default('google');
            $table->enum('status', ['pending', 'processing', 'complete', 'failed'])->default('pending');
            $table->json('source_segments')->nullable();
            $table->json('segments')->nullable();
            $table->json('exports')->nullable();
            $table->unsignedInteger('export_revision')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        Schema::table('billing_quotes', function (Blueprint $table): void {
            $table->foreignUlid('translation_id')->nullable()->unique()->constrained('translations')->nullOnDelete();
        });
        Schema::table('usage_charges', function (Blueprint $table): void {
            $table->ulid('transcription_id')->nullable()->change();
            $table->foreignUlid('translation_id')->nullable()->unique()->constrained('translations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('usage_charges')->whereNotNull('translation_id')->delete();
        Schema::table('usage_charges', function (Blueprint $table): void {
            $table->dropUnique(['translation_id']);
            $table->dropConstrainedForeignId('translation_id');
            $table->ulid('transcription_id')->nullable(false)->change();
        });
        Schema::table('billing_quotes', function (Blueprint $table): void {
            $table->dropUnique(['translation_id']);
            $table->dropConstrainedForeignId('translation_id');
        });
        Schema::dropIfExists('translations');
    }
};
