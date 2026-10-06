<?php

namespace App\Infrastructure\Billing;

use App\Domain\Billing\Contracts\MediaDurationInspectorInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class FfprobeMediaDurationInspector implements MediaDurationInspectorInterface
{
    public function __construct(private readonly SafeAudioUrl $urls, private readonly AudioDurationProbe $probe) {}

    public function measure(int $userId, array $source, string $quoteId): array
    {
        $upload = null;
        if (! empty($source['audio_storage_path'])) {
            $upload = UploadSession::where('user_id', $userId)
                ->where('storage_path', $source['audio_storage_path'])->where('status', 'completed')->first();
            if ($upload === null) {
                throw new BillingException('This file is not a completed upload belonging to your account.', 404);
            }
            $disk = Storage::disk('r2');
            $bytes = $disk->size($upload->storage_path);
            if ($bytes < 1 || $bytes > config('billing.media_max_bytes') || $bytes !== $upload->size) {
                throw new BillingException('This media is empty, incomplete, or exceeds the supported size.', 422);
            }
            $url = $disk->temporaryUrl($upload->storage_path, now()->addHours(6));
            $endpointHost = parse_url((string) config('filesystems.disks.r2.endpoint'), PHP_URL_HOST);
            if (parse_url($url, PHP_URL_SCHEME) === 'https' && is_string($endpointHost)
                && parse_url($url, PHP_URL_HOST) === $endpointHost
                && parse_url($url, PHP_URL_USER) === null) {
                $result = $this->probe->inspect($url, remote: true);
                if ($result !== null) {
                    return [
                        'duration_ms' => (int) ceil((float) $result['format']['duration'] * 1000),
                        'audio_storage_path' => $upload->storage_path,
                        'audio_url' => $url,
                        'file_name' => $upload->filename,
                    ];
                }
            }
        }

        $directory = storage_path('app/private/billing');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, 'media-');
        if ($path === false) {
            throw new BillingException('Media inspection is temporarily unavailable.', 503);
        }
        $destination = fopen($path, 'wb');
        if ($destination === false) {
            unlink($path);
            throw new BillingException('Media inspection is temporarily unavailable.', 503);
        }
        try {
            $storagePath = $source['audio_storage_path'] ?? null;
            $filename = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', rawurldecode(basename(parse_url($source['audio_url'], PHP_URL_PATH) ?: 'recording.mp3'))) ?: 'recording.mp3', 0, 255);
            if (! empty($source['audio_storage_path'])) {
                $filename = $upload->filename;
                $stream = Storage::disk('r2')->readStream($upload->storage_path);
                if (! is_resource($stream)) {
                    throw new BillingException('The uploaded file could not be opened.', 503);
                }
                try {
                    $bytes = stream_copy_to_stream($stream, $destination, config('billing.media_max_bytes') + 1);
                } finally {
                    fclose($stream);
                }
            } else {
                $bytes = $this->download($source['audio_url'], $destination);
            }
            fclose($destination);
            $destination = null;
            if (! is_int($bytes) || $bytes < 1 || $bytes > config('billing.media_max_bytes')) {
                throw new BillingException('This media is empty or exceeds the supported size.', 422);
            }
            $result = $this->probe->inspect($path);
            $seconds = $result['format']['duration'];
            if ($storagePath === null) {
                $format = explode(',', (string) ($result['format']['format_name'] ?? ''))[0];
                $extension = match ($format) {
                    'mov' => 'mp4', 'matroska' => 'webm', default => $format,
                };
                $storagePath = 'billing-media/'.$quoteId.'.'.$extension;
                $copy = fopen($path, 'rb');
                if (! is_resource($copy)) {
                    throw new BillingException('The verified media could not be opened.', 503);
                }
                try {
                    if (! Storage::disk('r2')->put($storagePath, $copy)) {
                        throw new BillingException('The verified media could not be stored.', 503);
                    }
                } finally {
                    if (is_resource($copy)) {
                        fclose($copy);
                    }
                }
            }

            return [
                'duration_ms' => (int) ceil((float) $seconds * 1000),
                'audio_storage_path' => $storagePath,
                'audio_url' => Storage::disk('r2')->temporaryUrl($storagePath, now()->addHours(6)),
                'file_name' => empty($source['audio_storage_path']) ? (pathinfo($filename, PATHINFO_FILENAME) ?: 'recording') : $filename,
            ];
        } finally {
            if (is_resource($destination)) {
                fclose($destination);
            }
            unlink($path);
        }
    }

    private function download(string $url, mixed $destination): int
    {
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $options = $this->urls->resolve($url) + ['allow_redirects' => false, 'stream' => true];
            $response = Http::withOptions($options)->connectTimeout(10)->timeout(600)->get($url);
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                $response->toPsrResponse()->getBody()->close();
                if (! is_string($location) || $location === '') {
                    throw new BillingException('The audio link redirect is invalid.', 422);
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }
            $response->throw();
            $body = $response->toPsrResponse()->getBody();
            $bytes = 0;
            try {
                while (! $body->eof()) {
                    $chunk = $body->read(1024 * 1024);
                    $bytes += strlen($chunk);
                    if ($bytes > config('billing.media_max_bytes')) {
                        throw new BillingException('The audio link exceeds the supported size.', 422);
                    }
                    if (fwrite($destination, $chunk) !== strlen($chunk)) {
                        throw new BillingException('Media inspection is temporarily unavailable.', 503);
                    }
                }
            } finally {
                $body->close();
            }

            return $bytes;
        }
        throw new BillingException('The audio link redirects too many times.', 422);
    }
}
