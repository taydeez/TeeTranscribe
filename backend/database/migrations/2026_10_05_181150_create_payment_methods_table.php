<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('public_config')->nullable();
            $table->timestamps();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUlid('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_method_id');
        });
        Schema::dropIfExists('payment_methods');
    }
};
