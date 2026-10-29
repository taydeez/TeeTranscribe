<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_status')->default('active');
            $table->timestamp('suspended_until')->nullable();
            $table->text('restriction_reason')->nullable();
            $table->ipAddress('signup_ip')->nullable();
            $table->json('signup_location')->nullable();
        });
        Schema::create('customer_admin_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->text('reason');
            $table->string('operation_key')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_admin_actions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'account_status', 'suspended_until', 'restriction_reason', 'signup_ip', 'signup_location',
        ]));
    }
};
