<?php

use App\Domain\Upload\Entities\UploadSession;
use App\Domain\Upload\Exceptions\UploadException;
use App\Infrastructure\Upload\R2\R2MultipartStorage;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->handler = new MockHandler;
    config(['filesystems.disks.r2' => [
        'driver' => 's3', 'key' => 'test-access', 'secret' => 'test-secret',
        'region' => 'auto', 'bucket' => 'media', 'endpoint' => 'https://test.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => true, 'request_checksum_calculation' => 'when_required',
        'retries' => 0, 'handler' => $this->handler,
    ]]);
    Storage::forgetDisk('r2');
    $this->gateway = new R2MultipartStorage;
    $this->upload = new UploadSession(
        id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', userId: 1, clientKey: 'client-key',
        filename: 'audio.mp3', contentType: 'audio/mpeg', size: 20 * 1024 * 1024,
        fingerprint: str_repeat('a', 64), storagePath: 'audio/example.mp3',
        partSize: 16 * 1024 * 1024, expiresAt: new DateTimeImmutable('+7 days'),
        providerUploadId: 'r2-upload-id',
    );
});

test('creates an R2 multipart object with session metadata and original content type', function (): void {
    $this->handler->append(new Result(['UploadId' => 'new-upload']));
    expect($this->gateway->begin($this->upload))->toBe('new-upload');
    $command = $this->handler->getLastCommand();
    expect($command->getName())->toBe('CreateMultipartUpload')
        ->and($command['ContentType'])->toBe('audio/mpeg')
        ->and($command['Metadata'])->toBe(['upload-session' => $this->upload->id]);
});

test('signs only the requested R2 part without sending file bytes', function (): void {
    $ticket = $this->gateway->signPart($this->upload, 2);
    parse_str(parse_url($ticket['upload_url'], PHP_URL_QUERY), $query);
    expect($query['uploadId'])->toBe('r2-upload-id')
        ->and($query['partNumber'])->toBe('2')
        ->and($query['X-Amz-Signature'])->not->toBeEmpty()
        ->and($ticket['headers'])->not->toHaveKeys(['Host', 'Content-Length'])
        ->and(json_encode($ticket))->not->toContain('test-secret')
        ->and(count($this->handler))->toBe(0);
});

test('lists every R2 parts page for authoritative resume progress', function (): void {
    $this->handler->append(
        new Result(['IsTruncated' => true, 'NextPartNumberMarker' => 1, 'Parts' => [['PartNumber' => 1, 'Size' => 16777216, 'ETag' => '"one"']]]),
        new Result(['IsTruncated' => false, 'Parts' => [['PartNumber' => 2, 'Size' => 4194304, 'ETag' => '"two"']]]),
    );
    expect($this->gateway->parts($this->upload))->toHaveCount(2);
    expect($this->handler->getLastCommand()['PartNumberMarker'])->toBe(1);
});

test('checks both object size and owning session metadata', function (): void {
    $this->handler->append(
        new Result(['ContentLength' => $this->upload->size, 'Metadata' => ['upload-session' => 'different-session']]),
        new Result(['ContentLength' => 1, 'Metadata' => ['upload-session' => $this->upload->id]]),
        new Result(['ContentLength' => $this->upload->size, 'Metadata' => ['upload-session' => $this->upload->id]]),
    );
    expect($this->gateway->verifiedObjectExists($this->upload))->toBeFalse()
        ->and($this->gateway->verifiedObjectExists($this->upload))->toBeFalse()
        ->and($this->gateway->verifiedObjectExists($this->upload))->toBeTrue();
});

test('completes R2 using part numbers and ETags returned by storage', function (): void {
    $this->handler->append(new Result);
    $this->gateway->complete($this->upload, [
        ['number' => 1, 'size' => 16777216, 'etag' => '"one"'],
        ['number' => 2, 'size' => 4194304, 'etag' => '"two"'],
    ]);
    expect($this->handler->getLastCommand()['MultipartUpload']['Parts'])->toBe([
        ['PartNumber' => 1, 'ETag' => '"one"'], ['PartNumber' => 2, 'ETag' => '"two"'],
    ]);
});

test('handles uploads already removed by the R2 lifecycle', function (): void {
    $this->handler->append(new S3Exception('Gone', new Command('ListParts'), ['code' => 'NoSuchUpload']));
    expect(fn () => $this->gateway->parts($this->upload))->toThrow(UploadException::class);
    $this->handler->append(new S3Exception('Gone', new Command('AbortMultipartUpload'), ['code' => 'NoSuchUpload']));
    $this->gateway->abort($this->upload);
    expect(count($this->handler))->toBe(0);
});
