<?php

namespace App\Domain\Upload\Contracts;

use App\Domain\Upload\Entities\UploadSession;

interface MultipartStorageInterface
{
    public function begin(UploadSession $session): string;

    /** @return list<array{number: int, size: int, etag: string}> */
    public function parts(UploadSession $session): array;

    /** @return array{upload_url: string, headers: array<string, string>} */
    public function signPart(UploadSession $session, int $number): array;

    public function complete(UploadSession $session, array $parts): void;

    public function abort(UploadSession $session): void;

    public function verifiedObjectExists(UploadSession $session): bool;

    public function audioUrl(UploadSession $session): string;
}
