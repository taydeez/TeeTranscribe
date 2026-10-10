<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_configurations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('activity')->unique();
            $table->jsonb('configuration');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('ai_provider_configuration_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('configuration_id')->constrained('ai_provider_configurations')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('before');
            $table->jsonb('after');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['configuration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_configuration_changes');
        Schema::dropIfExists('ai_provider_configurations');
    }
};
