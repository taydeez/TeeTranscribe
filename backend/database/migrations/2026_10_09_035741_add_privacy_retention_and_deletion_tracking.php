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
        Schema::table('users', function (Blueprint $table): void {
            $table->json('privacy_retention')->nullable();
        });
        foreach (['transcriptions', 'translations', 'dubbings', 'upload_sessions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->softDeletes();
                $table->timestamp('source_deleted_at')->nullable();
                $table->json('privacy_deleted_files')->nullable();
            });
        }
        Schema::table('upload_sessions', function (Blueprint $table): void {
            $table->string('source_kind', 20)->nullable();
        });
        Schema::create('privacy_deletions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('resource_type', 30);
            $table->string('resource_id', 26);
            $table->string('scope', 20);
            $table->string('category', 30)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload');
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
            $table->index(['resource_type', 'resource_id', 'scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_deletions');
        Schema::table('upload_sessions', fn (Blueprint $table) => $table->dropColumn('source_kind'));
        foreach (['transcriptions', 'translations', 'dubbings', 'upload_sessions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropSoftDeletes();
                $table->dropColumn(['source_deleted_at', 'privacy_deleted_files']);
            });
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('privacy_retention'));
    }
};
