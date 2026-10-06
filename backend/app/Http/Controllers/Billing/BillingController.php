<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Billing\Services\UsageQuoteService;
use App\Domain\Payment\Services\PaymentInvoiceService;
use App\Domain\Payment\Services\PaymentMethodService;
use App\Domain\Payment\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController
{
    public function __construct(
        private readonly BillingRepositoryInterface $repository,
        private readonly CreditService $credits,
        private readonly PaymentService $payments,
        private readonly PaymentMethodService $methods,
        private readonly UsageQuoteService $quotes,
    ) {}

    public function balance(Request $request): JsonResponse
    {
        return response()->json($this->credits->balance((int) $request->user()->getAuthIdentifier()));
    }

    public function packages(Request $request): JsonResponse
    {
        $data = $request->validate(['currency' => ['sometimes', 'in:NGN,USD']]);

        $currency = $data['currency'] ?? 'NGN';
        $methods = $this->methodData($currency);

        return response()->json(['data' => $this->payments->packagePrices($currency), 'payment_methods' => $methods, 'payments_enabled' => $methods !== [], 'usd_enabled' => $this->methods->available('USD') !== []]);
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        $data = $request->validate(['currency' => ['sometimes', 'in:NGN,USD']]);

        return response()->json(['data' => $this->methodData($data['currency'] ?? 'NGN')]);
    }

    private function methodData(string $currency): array
    {
        return array_map(fn ($method): array => ['id' => $method->id, 'name' => $method->name, 'code' => $method->code, 'description' => $method->description], $this->methods->available($currency));
    }

    public function history(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['payments', 'usage', 'ledger'], true), 404);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->repository->history((int) $request->user()->getAuthIdentifier(), $type, (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 20)));
    }

    public function createQuote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_key' => ['required', 'uuid'],
            'audio_url' => ['required', 'url:http,https', 'max:8192'],
            'audio_storage_path' => ['nullable', 'string', 'max:1024', 'regex:/\Aaudio\/[0-9A-HJKMNP-TV-Z]{26}\.(mp3|wav|m4a|mp4|ogg|oga|flac|webm|aac)\z/i'],
            'language_code' => ['required', 'string', 'regex:/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/'],
        ]);
        $key = $data['client_key'];
        unset($data['client_key']);
        $quote = $this->quotes->create((int) $request->user()->getAuthIdentifier(), $data, $key);

        return response()->json($this->quoteData($quote), 202);
    }

    public function quote(Request $request, string $quote): JsonResponse
    {
        $record = $this->repository->quote($quote, (int) $request->user()->getAuthIdentifier()) ?? throw new BillingException('Quote not found.', 404);

        return response()->json($this->quoteData($record));
    }

    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate(['client_key' => ['required', 'uuid'], 'package_id' => ['required', 'string', 'max:100'], 'currency' => ['required', 'in:NGN,USD'], 'payment_method' => ['required', 'string', 'max:100']]);
        $user = $request->user();

        return response()->json($this->paymentData($this->payments->quote((int) $user->getAuthIdentifier(), $user->email, $data['package_id'], $data['currency'], $data['client_key'], $data['payment_method'])), 201);
    }

    public function checkout(Request $request, string $payment): JsonResponse
    {
        return response()->json($this->paymentData($this->payments->checkout($payment, (int) $request->user()->getAuthIdentifier())));
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']]);

        return response()->json($this->paymentData($this->payments->verify($data['reference'], (int) $request->user()->getAuthIdentifier())));
    }

    public function invoice(Request $request, string $payment, PaymentInvoiceService $invoices): JsonResponse
    {
        return response()->json($invoices->download($payment, (int) $request->user()->getAuthIdentifier()))
            ->header('Cache-Control', 'private, no-store');
    }

    private function quoteData(array $quote): array
    {
        $balance = $this->credits->balance((int) $quote['user_id']);

        return array_intersect_key($quote, array_flip(['id', 'status', 'provider', 'model', 'quantity', 'credit_units', 'expires_at', 'failure_reason', 'transcription_id'])) + [
            'file_name' => $quote['source']['file_name'] ?? null,
            'available_units' => $balance['available_units'],
            'enough_credits' => $quote['credit_units'] !== null && $balance['available_units'] >= $quote['credit_units'],
        ];
    }

    private function paymentData(array $payment): array
    {
        return array_intersect_key($payment, array_flip(['id', 'reference', 'gateway', 'payment_method_id', 'package_name', 'credit_units', 'amount_minor', 'currency', 'fx_ngn_per_usd_micros', 'expires_at', 'status', 'checkout_url', 'paid_at']));
    }
}
