<?php

namespace App\Domain\Dubbing\Contracts;

interface DubbingGatewayResolverInterface
{
    /** @return array{provider: string, model: string, options: array, configured: bool} */
    public function definition(string $mediaType = 'video'): array;

    public function resolve(string $provider): DubbingGatewayInterface;

    public function languages(string $mediaType = 'video'): array;
}
