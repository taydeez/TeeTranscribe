<?php

namespace App\Domain\Transcriber\Contracts;

interface TranscriberGatewayResolverInterface
{
    /** @return array{provider: string, model: string} */
    public function definition(?string $languageCode = null): array;

    public function resolve(?string $languageCode = null, ?string $provider = null): TranscriberGatewayInterface;
}
