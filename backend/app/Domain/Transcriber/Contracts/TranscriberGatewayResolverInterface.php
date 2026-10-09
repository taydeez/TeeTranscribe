<?php

namespace App\Domain\Transcriber\Contracts;

interface TranscriberGatewayResolverInterface
{
    public function resolve(?string $languageCode = null, ?string $provider = null): TranscriberGatewayInterface;
}
