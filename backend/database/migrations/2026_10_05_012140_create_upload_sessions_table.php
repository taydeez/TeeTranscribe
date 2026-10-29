<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_key');
            $table->string('filename');
            $table->string('content_type');
            $table->unsignedBigInteger('size');
            $table->char('fingerprint', 64);
            $table->string('storage_path')->unique();
            $table->text('provider_upload_id')->nullable();
            $table->unsignedInteger('part_size');
            $table->string('status')->default('uploading');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'client_key']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
