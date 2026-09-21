<?php

namespace App\Domain\Folder\Entities;

final readonly class FolderPage
{
    /** @param list<Folder> $data */
    public function __construct(
        public array $data,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}
}
