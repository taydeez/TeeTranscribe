<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained();
            $table->unsignedBigInteger('available_units')->default(0);
            $table->unsignedBigInteger('reserved_units')->default(0);
            $table->timestamps();
        });
        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('wallet_id')->constrained('credit_wallets');
            $table->foreignId('user_id')->constrained();
            $table->string('event_key')->unique();
            $table->string('kind');
            $table->unsignedBigInteger('amount_units');
            $table->unsignedBigInteger('available_after');
            $table->unsignedBigInteger('reserved_after');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('billing_quotes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->uuid('client_key');
            $table->string('activity');
            $table->string('provider');
            $table->string('model');
            $table->json('rate');
            $table->json('source');
            $table->json('request_source');
            $table->unsignedBigInteger('quantity')->nullable();
            $table->unsignedBigInteger('credit_units')->nullable();
            $table->string('status')->default('measuring');
            $table->string('failure_reason')->nullable();
            $table->ulid('transcription_id')->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'client_key']);
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->uuid('client_key');
            $table->string('reference')->unique();
            $table->string('package_key');
            $table->string('package_name');
            $table->string('customer_email');
            $table->unsignedBigInteger('credit_units');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedBigInteger('fx_ngn_per_usd_micros')->nullable();
            $table->string('environment');
            $table->string('status')->default('quoted');
            $table->text('checkout_url')->nullable();
            $table->string('provider_transaction_id')->nullable()->unique();
            $table->unsignedBigInteger('provider_fee_minor')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'client_key']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('usage_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->foreignUlid('quote_id')->unique()->constrained('billing_quotes');
            $table->ulid('transcription_id')->unique();
            $table->string('activity');
            $table->string('provider');
            $table->string('model');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('credit_units');
            $table->json('rate');
            $table->string('status');
            $table->unsignedBigInteger('provider_quantity')->nullable();
            $table->unsignedBigInteger('provider_cost_micros')->nullable();
            $table->string('provider_cost_currency', 3)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['usage_charges', 'payments', 'billing_quotes', 'credit_transactions', 'credit_wallets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
