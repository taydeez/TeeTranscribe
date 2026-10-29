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
        Schema::create('operational_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 40);
            $table->string('level', 20);
            $table->text('message');
            $table->string('operation_type', 40)->nullable();
            $table->string('operation_id', 100)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_request_id', 255)->nullable();
            $table->string('job_id', 100)->nullable();
            $table->unsignedBigInteger('elapsed_ms')->nullable();
            $table->jsonb('context');
            $table->timestamp('created_at', 6)->index();
            $table->index(['type', 'created_at']);
            $table->index(['level', 'created_at']);
            $table->index(['operation_type', 'operation_id', 'created_at']);
            $table->index(['provider', 'created_at']);
            $table->index('provider_request_id');
            $table->index('job_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operational_logs');
    }
};
