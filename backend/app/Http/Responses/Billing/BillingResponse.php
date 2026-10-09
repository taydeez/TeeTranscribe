<?php

namespace App\Http\Responses\Billing;

use App\Domain\Billing\Services\CreditService;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class BillingResponse extends ApiResponse
{
    public function __construct(private readonly CreditService $credits) {}

    public function methodData(array $methods): array
    {
        return array_map(fn ($method): array => ['id' => $method->id, 'name' => $method->name, 'code' => $method->code, 'description' => $method->description], $methods);
    }

    public function quoteData(array $quote): array
    {
        $balance = $this->credits->balance((int) $quote['user_id']);

        return array_intersect_key($quote, array_flip(['id', 'status', 'provider', 'model', 'quantity', 'credit_units', 'expires_at', 'failure_reason', 'transcription_id'])) + [
            'file_name' => $quote['source']['file_name'] ?? null,
            'available_units' => $balance['available_units'],
            'enough_credits' => $quote['credit_units'] !== null && $balance['available_units'] >= $quote['credit_units'],
        ];
    }

    public function paymentData(array $payment): array
    {
        return array_intersect_key($payment, array_flip(['id', 'reference', 'gateway', 'payment_method_id', 'package_name', 'credit_units', 'amount_minor', 'currency', 'fx_ngn_per_usd_micros', 'expires_at', 'status', 'checkout_url', 'paid_at']));
    }

    public function packages(array $prices, array $methods, bool $usdEnabled): JsonResponse
    {
        return $this->json(['data' => $prices, 'payment_methods' => $this->methodData($methods), 'payments_enabled' => $methods !== [], 'usd_enabled' => $usdEnabled]);
    }

    public function paymentMethods(array $methods): JsonResponse
    {
        return $this->json(['data' => $this->methodData($methods)]);
    }

    public function invoice(array $invoice): JsonResponse
    {
        return $this->json($invoice)->header('Cache-Control', 'private, no-store');
    }
}
