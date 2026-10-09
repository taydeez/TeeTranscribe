<?php

namespace App\Domain\Privacy\Contracts;

interface PrivacyStorageInterface
{
    /** Delete only the owned storage paths and prefixes in a saved deletion manifest. */
    public function purge(array $manifest): void;
}
