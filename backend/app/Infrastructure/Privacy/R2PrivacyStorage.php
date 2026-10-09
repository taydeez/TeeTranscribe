<?php

namespace App\Infrastructure\Privacy;

use App\Domain\Privacy\Contracts\PrivacyStorageInterface;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechAudioStorage;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class R2PrivacyStorage implements PrivacyStorageInterface
{
    public function purge(array $manifest): void
    {
        $disk = Storage::disk('r2');
        foreach ($manifest['multiparts'] ?? [] as $upload) {
            $this->validPath($upload['path']);
            try {
                $disk->getClient()->abortMultipartUpload(['Bucket' => config('filesystems.disks.r2.bucket'),
                    'Key' => $upload['path'], 'UploadId' => $upload['upload_id'], '@http' => ['connect_timeout' => 5, 'timeout' => 30]]);
            } catch (S3Exception $exception) {
                if ($exception->getAwsErrorCode() !== 'NoSuchUpload') {
                    throw $exception;
                }
            }
        }
        $paths = array_merge($manifest['paths'] ?? [], $manifest['source_paths'] ?? []);
        foreach ($manifest['prefixes'] ?? [] as $prefix) {
            $this->validPrefix($prefix);
            array_push($paths, ...$disk->allFiles(rtrim($prefix, '/')));
        }
        foreach ($manifest['format_prefixes'] ?? [] as $selection) {
            $this->validPrefix($selection['prefix']);
            foreach ($disk->allFiles(rtrim($selection['prefix'], '/')) as $path) {
                if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $selection['extensions'], true)) {
                    $paths[] = $path;
                }
            }
        }
        foreach (array_unique($paths) as $path) {
            $this->validPath($path);
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException('A workspace file could not be deleted.');
            }
            if ($disk->exists($path)) {
                throw new RuntimeException('A workspace file deletion could not be verified.');
            }
        }
        foreach ($manifest['google_ids'] ?? [] as $id) {
            app(GoogleSpeechAudioStorage::class)->remove($id);
        }
    }

    private function validPrefix(string $prefix): void
    {
        if (! preg_match('~^(exports|translations|dubbings|transcription-inputs)/[A-Za-z0-9]{26}/$~', $prefix)) {
            throw new RuntimeException('Invalid owned file prefix.');
        }
    }

    private function validPath(string $path): void
    {
        if (! preg_match('~^(audio|billing-media|exports|translations|dubbings|transcription-inputs)/[^\\\\]+$~', $path)
            || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid owned file path.');
        }
    }
}
