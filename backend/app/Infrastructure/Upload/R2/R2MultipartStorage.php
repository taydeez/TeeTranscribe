<?php

namespace App\Infrastructure\Upload\R2;

use App\Domain\Upload\Contracts\MultipartStorageInterface;
use App\Domain\Upload\Entities\UploadSession;
use App\Domain\Upload\Exceptions\UploadException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

final class R2MultipartStorage implements MultipartStorageInterface
{
    private function client(): S3Client
    {
        foreach (['key', 'secret', 'bucket', 'endpoint'] as $setting) {
            abort_unless(config('filesystems.disks.r2.'.$setting), 503, 'Audio storage is not configured.');
        }

        return Storage::disk('r2')->getClient();
    }

    private function object(UploadSession $session): array
    {
        return [
            'Bucket' => config('filesystems.disks.r2.bucket'), 'Key' => $session->storagePath,
            '@http' => ['connect_timeout' => 5, 'timeout' => 20],
        ];
    }

    private function multipart(UploadSession $session): array
    {
        return $this->object($session) + ['UploadId' => $session->providerUploadId];
    }

    public function begin(UploadSession $session): string
    {
        return $this->client()->createMultipartUpload($this->object($session) + [
            'ContentType' => $session->contentType,
            'Metadata' => ['upload-session' => $session->id],
        ])->get('UploadId');
    }

    public function parts(UploadSession $session): array
    {
        if ($session->providerUploadId === null) {
            return [];
        }
        $parts = [];
        try {
            foreach ($this->client()->getPaginator('ListParts', $this->multipart($session)) as $page) {
                foreach ($page['Parts'] ?? [] as $part) {
                    $parts[] = ['number' => (int) $part['PartNumber'], 'size' => (int) $part['Size'], 'etag' => (string) $part['ETag']];
                }
            }
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() === 'NoSuchUpload') {
                throw new UploadException('This upload no longer exists in storage. Start a new upload.', 410);
            }
            throw $exception;
        }

        return $parts;
    }

    public function signPart(UploadSession $session, int $number): array
    {
        $client = $this->client();
        $command = $client->getCommand('UploadPart', $this->multipart($session) + [
            'PartNumber' => $number,
            'ContentLength' => $session->partBytes($number),
        ]);
        $request = $client->createPresignedRequest($command, '+20 minutes');
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (! in_array(strtolower($name), ['host', 'content-length'], true)) {
                $headers[$name] = implode(', ', $values);
            }
        }

        return ['upload_url' => (string) $request->getUri(), 'headers' => $headers];
    }

    public function complete(UploadSession $session, array $parts): void
    {
        $this->client()->completeMultipartUpload($this->multipart($session) + [
            'MultipartUpload' => ['Parts' => array_map(
                fn (array $part): array => ['PartNumber' => $part['number'], 'ETag' => $part['etag']], $parts,
            )],
        ]);
    }

    public function abort(UploadSession $session): void
    {
        try {
            $this->client()->abortMultipartUpload($this->multipart($session));
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $exception;
            }
        }
    }

    public function verifiedObjectExists(UploadSession $session): bool
    {
        try {
            $object = $this->client()->headObject($this->object($session));

            return (int) $object['ContentLength'] === $session->size
                && ($object['Metadata']['upload-session'] ?? null) === $session->id;
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() === 404) {
                return false;
            }
            throw $exception;
        }
    }

    public function audioUrl(UploadSession $session): string
    {
        return Storage::disk('r2')->temporaryUrl($session->storagePath, now()->addHours(6));
    }
}
