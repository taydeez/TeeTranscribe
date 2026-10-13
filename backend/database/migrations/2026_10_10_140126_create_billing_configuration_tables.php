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
        Schema::create('billing_configurations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->jsonb('configuration');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('billing_configuration_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('configuration_id')->constrained('billing_configurations');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('before');
            $table->jsonb('after');
            $table->text('reason');
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_configuration_changes');
        Schema::dropIfExists('billing_configurations');
    }
};
