<?php

namespace App\Domain\Translation\Contracts;

interface TranslationGatewayResolverInterface
{
    /** @return array{provider: string, model: string, configured: bool, max_segments: int} */
    public function definition(?string $language = null): array;

    public function resolve(string $provider, ?string $model = null): TranslationGatewayInterface;

    /** @return list<array{code: string, name: string}> */
    public function languages(?string $provider = null): array;
}
