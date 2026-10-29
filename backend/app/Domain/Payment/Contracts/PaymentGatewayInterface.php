<?php

namespace App\Domain\Payment\Contracts;

interface PaymentGatewayInterface
{
    public function code(): string;

    public function webhookIsValid(string $payload, string $signature): bool;

    public function referenceFromWebhook(array $payload): ?string;

    public function initialize(array $payment, string $email): string;

    public function verify(string $reference): array;
}
