<?php

namespace App\Domain\Translation\Contracts;

interface TranslationGatewayInterface
{
    /** @return list<array{code: string, name: string}> */
    public function languages(): array;

    /** @return array{texts: list<string>, detected_language: string|null} */
    public function translate(array $texts, ?string $source, string $target): array;
}
