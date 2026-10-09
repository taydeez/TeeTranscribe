<?php

namespace App\Infrastructure\AI\Transcriber\Google;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class GoogleSpeechAudioStorage
{
    public function __construct(private readonly GoogleSpeechCredentials $credentials) {}

    public function stage(string $id, string $r2Path): string
    {
        $bucket = $this->bucket();
        $source = Storage::disk('r2')->readStream($r2Path);
        if (! is_resource($source)) {
            throw new RuntimeException('The verified audio could not be read from R2.');
        }
        try {
            Http::withToken($this->credentials->token())->connectTimeout(10)->timeout(600)
                ->withQueryParameters(['uploadType' => 'media', 'name' => $this->object($id)])
                ->withBody($source, 'application/octet-stream')
                ->post('https://storage.googleapis.com/upload/storage/v1/b/'.rawurlencode($bucket).'/o')->throw();
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        return 'gs://'.$bucket.'/'.$this->object($id);
    }

    public function remove(string $id): void
    {
        $response = Http::withToken($this->credentials->token())->connectTimeout(10)->timeout(30)
            ->delete('https://storage.googleapis.com/storage/v1/b/'.rawurlencode($this->bucket()).'/o/'.rawurlencode($this->object($id)));
        if (! $response->notFound()) {
            $response->throw();
        }
    }

    private function bucket(): string
    {
        $bucket = config('transcriber.google.bucket');
        if (! is_string($bucket) || ! preg_match('/^[a-z0-9][a-z0-9._-]{1,220}[a-z0-9]$/', $bucket)) {
            throw new RuntimeException('Google Speech staging bucket is not configured.');
        }

        return $bucket;
    }

    private function object(string $id): string
    {
        if (! preg_match('/^[a-zA-Z0-9]{26}$/', $id)) {
            throw new RuntimeException('Invalid transcription identifier.');
        }

        return 'transcription-inputs/'.$id;
    }
}
