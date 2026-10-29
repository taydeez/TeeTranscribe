<?php

namespace App\Domain\Folder\Exceptions;

use RuntimeException;

final class FolderNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $folderId)
    {
        parent::__construct("Folder {$folderId} was not found.");
    }
}
