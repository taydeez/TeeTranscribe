<?php

use App\Domain\Upload\Entities\UploadSession;

test('calculates chunk boundaries for small and large media without losing tail bytes', function (int $size, int $count, int $tail): void {
    $session = new UploadSession(
        id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', userId: 1, clientKey: 'key',
        filename: 'recording.mp3', contentType: 'audio/mpeg', size: $size,
        fingerprint: str_repeat('a', 64), storagePath: 'audio/recording.mp3',
        partSize: 16 * 1024 * 1024, expiresAt: new DateTimeImmutable('+7 days'),
    );
    expect($session->partCount())->toBe($count)
        ->and($session->partBytes($count))->toBe($tail);
    $bytes = 0;
    for ($part = 1; $part <= $count; $part++) {
        $bytes += $session->partBytes($part);
    }
    expect($bytes)->toBe($size);
})->with([
    [1, 1, 1],
    [16 * 1024 * 1024, 1, 16 * 1024 * 1024],
    [20 * 1024 * 1024, 2, 4 * 1024 * 1024],
    [5 * 1024 * 1024 * 1024, 320, 16 * 1024 * 1024],
]);
