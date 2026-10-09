<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Infrastructure\AI\OpenAI\OpenAIClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['openai.key' => 'test-openai-secret', 'openai.endpoint' => 'https://api.openai.com/v1/',
        'openai.text_model' => 'gpt-4.1-mini', 'openai.timeout' => 180, 'openai.max_output_tokens' => 16000]);
    $this->schema = ['type' => 'object', 'properties' => ['text' => ['type' => 'string']],
        'required' => ['text'], 'additionalProperties' => false];
});

test('structured responses use the saved model strict schema and no provider storage', function () {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response([
        'status' => 'completed', 'output' => [
            ['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'status' => 'completed', 'content' => [
                ['type' => 'output_text', 'text' => '{"text":"Hello'],
                ['type' => 'output_text', 'text' => ' world."}'],
            ]],
        ],
    ])]);

    expect(app(OpenAIClient::class)->structured('Clean punctuation.', 'hello world', $this->schema, 'cleanup', 'saved-model'))
        ->toBe(['text' => 'Hello world.']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-openai-secret')
        && $request['store'] === false && $request['model'] === 'saved-model'
        && $request['instructions'] === 'Clean punctuation.' && $request['input'] === 'hello world'
        && $request['text']['format']['strict'] === true && $request['text']['format']['schema'] === $this->schema);
});

test('incomplete refused and malformed structured responses never publish generated text', function (array $response, string $message) {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($response)]);

    expect(fn () => app(OpenAIClient::class)->structured('Clean.', 'Private text.', $this->schema, 'cleanup'))
        ->toThrow(BillingException::class, $message);
    Http::assertSentCount(1);
})->with([
    'output limit' => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []], 'incomplete response'],
    'refusal' => [['status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed',
        'content' => [['type' => 'refusal', 'refusal' => 'Sensitive provider explanation.']]]]], 'could not process'],
    'invalid JSON' => [['status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed',
        'content' => [['type' => 'output_text', 'text' => '{broken']]]]], 'invalid response'],
    'truncated message' => [['status' => 'completed', 'output' => [['type' => 'message', 'status' => 'incomplete',
        'content' => [['type' => 'output_text', 'text' => '{"text":"unsafe partial result"}']]]]], 'incomplete response'],
    'unexpected output shape' => [['status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed',
        'content' => [['type' => 'output_text', 'text' => '["wrong root"]']]]]], 'invalid response'],
]);

test('failed paid requests are not automatically retried or logged with private text', function () {
    Log::spy();
    Http::fake(['https://api.openai.com/v1/responses' => Http::response([
        'error' => ['message' => 'Private transcript and test-openai-secret', 'type' => 'server_error'],
    ], 503)]);

    expect(fn () => app(OpenAIClient::class)->structured('Clean.', 'Private transcript.', $this->schema, 'cleanup'))
        ->toThrow(BillingException::class, 'temporarily unavailable');
    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->once()->with('OpenAI text request failed.', ['http_status' => 503]);
});
