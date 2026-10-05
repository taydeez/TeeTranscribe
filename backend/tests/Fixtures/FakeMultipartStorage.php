<?php

namespace Tests\Fixtures;

use App\Domain\Upload\Contracts\MultipartStorageInterface;
use App\Domain\Upload\Entities\UploadSession;
use RuntimeException;

final class FakeMultipartStorage implements MultipartStorageInterface
{
    public int $begins = 0;

    public int $completions = 0;

    public int $aborts = 0;

    public bool $failAfterCompletion = false;

    public array $uploadedParts = [];

    public array $objects = [];

    public function begin(UploadSession $session): string
    {
        $this->begins++;

        return 'provider-'.$session->id;
    }

    public function parts(UploadSession $session): array
    {
        return $this->uploadedParts[$session->id] ?? [];
    }

    public function signPart(UploadSession $session, int $number): array
    {
        return ['upload_url' => 'https://storage.example/part?number='.$number, 'headers' => []];
    }

    public function complete(UploadSession $session, array $parts): void
    {
        $this->completions++;
        $this->objects[$session->id] = true;
        if ($this->failAfterCompletion) {
            $this->failAfterCompletion = false;
            throw new RuntimeException('Response lost after object was completed.');
        }
    }

    public function abort(UploadSession $session): void
    {
        $this->aborts++;
        unset($this->uploadedParts[$session->id]);
    }

    public function verifiedObjectExists(UploadSession $session): bool
    {
        return $this->objects[$session->id] ?? false;
    }

    public function audioUrl(UploadSession $session): string
    {
        return 'https://storage.example/'.$session->storagePath.'?signed=read';
    }
}
