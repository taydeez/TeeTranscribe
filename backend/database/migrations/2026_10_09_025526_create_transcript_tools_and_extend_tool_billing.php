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
        Schema::create('transcript_tools', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->foreignUlid('transcription_id')->constrained()->cascadeOnDelete();
            $table->enum('operation', ['cleanup', 'summary']);
            $table->enum('status', ['pending', 'processing', 'complete', 'failed'])->default('pending');
            $table->string('source_hash', 64);
            $table->longText('source_text');
            $table->json('source_segments')->nullable();
            $table->string('provider')->default('openai');
            $table->string('model');
            $table->json('result')->nullable();
            $table->json('progress')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['transcription_id', 'operation', 'source_hash']);
            $table->index(['user_id', 'created_at']);
        });
        Schema::table('billing_quotes', function (Blueprint $table): void {
            $table->foreignUlid('transcript_tool_id')->nullable()->constrained('transcript_tools')->nullOnDelete();
        });
        Schema::table('usage_charges', function (Blueprint $table): void {
            $table->ulid('transcript_tool_id')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('usage_charges', function (Blueprint $table): void {
            $table->dropUnique(['transcript_tool_id']);
            $table->dropColumn('transcript_tool_id');
        });
        Schema::table('billing_quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transcript_tool_id');
        });
        Schema::dropIfExists('transcript_tools');
    }
};
