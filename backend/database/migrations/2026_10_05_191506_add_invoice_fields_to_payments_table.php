<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('invoice_number')->nullable()->unique();
            $table->string('invoice_storage_path')->nullable();
            $table->timestamp('invoice_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['invoice_number']);
            $table->dropColumn(['invoice_number', 'invoice_storage_path', 'invoice_notified_at']);
        });
    }
};
