<?php

use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Mappers\TranscriptionMapper;

test('round trips guest ownership without an authenticated user', function () {
    $mapper = new TranscriptionMapper;
    $entity = new Transcription(
        id: '01arz3ndektsv4rrffq69g5fav',
        userId: null,
        audioPath: 'audio/guest.mp3', fileName: 'guest.mp3', name: 'guest',
        guestSessionId: '01994fab-4658-7b00-a001-123456789abc',
    );

    $mapped = $mapper->fromArray($mapper->toArray($entity));

    expect($mapped)->toEqual($entity);
});

test('round trips transcription data without losing text or timestamp precision', function () {
    $data = [
        'id' => '01arz3ndektsv4rrffq69g5fav',
        'user_id' => 7,
        'guest_session_id' => null,
        'audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null,
        'duration' => 123.456,
        'status' => 'completed',
        'provider_request_id' => 'provider-42',
        'transcript' => "Hello — Ẹ káàárọ̀.\nSecond line.",
        'created_at' => '2026-09-17T12:30:00.123456+01:00',
        'updated_at' => '2026-09-17T12:35:00.654321+01:00',
    ];
    $mapper = new TranscriptionMapper;

    $entity = $mapper->fromArray($data);

    expect($entity)->toBeInstanceOf(Transcription::class);
    expect($entity->createdAt)->toBeInstanceOf(DateTimeImmutable::class);
    expect($mapper->toArray($entity))->toBe($data);
});

test('maps omitted optional values to null', function () {
    $data = ['id' => '01arz3ndektsv4rrffq69g5fav', 'audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null];
    $mapper = new TranscriptionMapper;

    $entity = $mapper->fromArray($data);

    expect($mapper->toArray($entity))->toBe([
        'id' => '01arz3ndektsv4rrffq69g5fav',
        'user_id' => null,
        'guest_session_id' => null,
        'audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null,
        'duration' => null,
        'status' => 'pending',
        'provider_request_id' => null,
        'transcript' => null,
        'created_at' => null,
        'updated_at' => null,
    ]);
});

test('copies mutable dates so subsequent changes cannot mutate the entity', function () {
    $date = new DateTime('2026-09-17T12:00:00+01:00');
    $mapper = new TranscriptionMapper;
    $data = [
        'id' => '01arz3ndektsv4rrffq69g5fav', 'user_id' => 2, 'audio_path' => 'audio/sample.mp3', 'file_name' => 'sample.mp3', 'name' => 'sample', 'folder_name' => null, 'status' => 'pending',
        'created_at' => $date, 'updated_at' => $date,
    ];

    $entity = $mapper->fromArray($data);
    $date->modify('+1 day');

    expect($entity->createdAt?->format(DATE_ATOM))->toBe('2026-09-17T12:00:00+01:00');
    expect($entity->updatedAt?->format(DATE_ATOM))->toBe('2026-09-17T12:00:00+01:00');
});
