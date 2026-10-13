<?php

use App\Infrastructure\Observability\LogSanitizer;
use Illuminate\Database\QueryException;

test('exception detail keeps causes and locations without arguments credentials or signed links', function () {
    $sanitizer = new LogSanitizer;
    $value = $sanitizer->exception(new RuntimeException('Request failed', previous: new RuntimeException('password="hidden" https://example.com/audio?token=private person@example.com')));
    $encoded = json_encode($value);
    expect($value)->toHaveCount(2)->and($value[0]['message'])->toBe('Request failed')
        ->and($encoded)->not->toContain('hidden')->not->toContain('token=private')->not->toContain('person@example.com');
});

test('nested operational context is bounded and removes private media and credentials', function () {
    $sanitizer = new LogSanitizer;
    $context = $sanitizer->context(['transcription_id' => 'one', 'source_text' => 'private text', 'nested' => ['api_key' => 'private-key', 'status' => 'failed'], 'response' => ['audio_transcript' => 'private words']]);
    expect($context['transcription_id'])->toBe('one')->and($context['nested']['status'])->toBe('failed')
        ->and(json_encode($context))->not->toContain('private text')->not->toContain('private-key')->not->toContain('private words');
});

test('database exception causes cannot expose bound SQL values', function () {
    $exception = new QueryException('pgsql', 'insert into transcripts values (?)', ['private spoken words'], new PDOException('DETAIL: row contains private spoken words'));
    expect(json_encode((new LogSanitizer)->exception($exception)))->not->toContain('private spoken words')->toContain('SQL values omitted');
});
