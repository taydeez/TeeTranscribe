<?php

namespace App\Domain\Dubbing\Contracts;

use App\Domain\Dubbing\Entities\Dubbing;

interface DubbingGatewayInterface
{
    public function create(Dubbing $record, string $sourceUrl): array;

    public function recover(string $reference): ?array;

    public function project(string $id): array;

    public function language(string $projectId, string $languageId): array;
}
