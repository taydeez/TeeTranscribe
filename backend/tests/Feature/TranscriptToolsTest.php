<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Transcriber\Services\ProcessTranscriptTool;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptTool;
use App\Jobs\ProcessTranscriptTool as ProcessTranscriptToolJob;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    config(['openai.key' => 'test-key', 'openai.endpoint' => 'https://api.openai.com/v1/', 'openai.text_model' => 'gpt-4.1-mini',
        'billing.free_credits' => '0',
        'billing.rates.cleanup.openai' => ['gpt-4.1-mini' => ['unit' => '1000_characters', 'credits' => '10', 'provider_cost' => '']],
        'billing.rates.summary.openai' => ['gpt-4.1-mini' => ['unit' => '1000_characters', 'credits' => '20', 'provider_cost' => '']]]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'transcript-tool-credit-purchase');
    $this->record = Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'complete',
        'transcript' => "um hello alice\nwelcome bob", 'provider' => 'deepgram',
        'segments' => [
            ['start' => 0.25, 'end' => 1.75, 'speaker' => 'Alice', 'text' => 'um hello alice', 'confidence' => 0.94],
            ['start' => 2.25, 'end' => 3.75, 'speaker' => 'Bob', 'text' => 'welcome bob', 'confidence' => 0.87],
        ]]);
    $this->base = '/api/v1/transcriptions/'.$this->record->id.'/tools';
    $this->providerResult = ['texts' => ['Hello, Alice.', 'Welcome, Bob.']];
    Http::fake(['api.openai.com/v1/responses' => function () {
        return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => json_encode($this->providerResult, JSON_THROW_ON_ERROR)]]]]]);
    }]);
});

function toolQuote($test, string $operation = 'cleanup', ?string $key = null): array
{
    return $test->postJson($test->base.'/quotes', ['operation' => $operation, 'client_key' => $key ?? (string) Str::uuid()])
        ->assertOk()->assertJsonPath('status', 'ready')->json();
}

function submitTool($test, array $quote): array
{
    return $test->postJson($test->base, ['quote_id' => $quote['id']])->assertSuccessful()->json();
}

test('tools are account scoped and quotes do not reserve credits or request generation', function () {
    $this->getJson($this->base)->assertOk()->assertJsonPath('configured', true)->assertJsonPath('data', []);
    $quote = toolQuote($this);
    expect($quote['credit_units'])->toBeGreaterThan(0)->and($quote['enough_credits'])->toBeTrue()
        ->and(app(CreditService::class)->balance($this->user->id))->toMatchArray(['available_units' => 10000, 'reserved_units' => 0]);
    $this->assertDatabaseCount('transcript_tools', 0);
    $this->assertDatabaseCount('usage_charges', 0);
    Http::assertNothingSent();
    $tool = submitTool($this, $quote);
    $other = User::factory()->create();
    Sanctum::actingAs($other);
    $this->getJson($this->base)->assertNotFound();
    $this->getJson($this->base.'/'.$tool['id'])->assertNotFound();
    $this->postJson($this->base.'/quotes', ['operation' => 'cleanup', 'client_key' => (string) Str::uuid()])->assertNotFound();
    $this->postJson($this->base, ['quote_id' => $quote['id']])->assertNotFound();
    $otherRecord = Transcription::factory()->create(['user_id' => $other->id, 'status' => 'complete', 'transcript' => 'Another transcript.']);
    $this->getJson('/api/v1/transcriptions/'.$otherRecord->id.'/tools/'.$tool['id'])->assertNotFound();
});

test('repeat confirmation and new quotes for unchanged text reuse one generation and charge', function () {
    $key = (string) Str::uuid();
    $quote = toolQuote($this, key: $key);
    expect(toolQuote($this, key: $key)['id'])->toBe($quote['id']);
    $first = submitTool($this, $quote);
    expect(submitTool($this, $quote)['id'])->toBe($first['id']);
    $secondQuote = toolQuote($this);
    expect(submitTool($this, $secondQuote)['id'])->toBe($first['id']);
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray([
        'available_units' => 10000 - $quote['credit_units'], 'reserved_units' => $quote['credit_units']]);
    $this->assertDatabaseCount('transcript_tools', 1);
    $this->assertDatabaseCount('usage_charges', 1);
    expect(OutboxEvent::where('event_type', 'TranscriptToolRequested')->count())->toBe(1);
    app(ProcessTranscriptTool::class)->handle($first['id']);
    $thirdQuote = toolQuote($this);
    $this->postJson($this->base, ['quote_id' => $thirdQuote['id']])->assertOk()->assertJsonPath('id', $first['id'])
        ->assertJsonPath('status', 'complete');
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray([
        'available_units' => 10000 - $quote['credit_units'], 'reserved_units' => 0]);
    $this->assertDatabaseCount('usage_charges', 1);
    Http::assertSentCount(1);
});

test('edited source text rejects its old quote and marks earlier generated suggestions stale', function () {
    $oldQuote = toolQuote($this);
    $tool = submitTool($this, $oldQuote);
    app(ProcessTranscriptTool::class)->handle($tool['id']);
    $unsubmitted = toolQuote($this, 'summary');
    $this->record->update(['transcript' => 'Edited after the quote.', 'segments' => []]);
    $this->postJson($this->base, ['quote_id' => $unsubmitted['id']])->assertStatus(409);
    $this->getJson($this->base.'/'.$tool['id'])->assertOk()->assertJsonPath('stale', true);
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.0.stale', true);
    $this->assertDatabaseCount('usage_charges', 1);
    expect(app(CreditService::class)->balance($this->user->id)['reserved_units'])->toBe(0);
});

test('cleanup saves a suggestion while preserving speaker timing and the original transcript', function () {
    $originalText = $this->record->transcript;
    $originalSegments = $this->record->segments;
    $quote = toolQuote($this);
    $tool = submitTool($this, $quote);
    app(ProcessTranscriptTool::class)->handle($tool['id']);
    $response = $this->getJson($this->base.'/'.$tool['id'])->assertOk()->assertJsonPath('status', 'complete')
        ->assertJsonPath('stale', false)->assertJsonPath('result.segments.0.text', 'Hello, Alice.')
        ->assertJsonPath('result.segments.1.text', 'Welcome, Bob.')->json();
    foreach ($originalSegments as $index => $segment) {
        expect($response['result']['segments'][$index])->toMatchArray(array_diff_key($segment, ['text' => true]));
    }
    expect($response['result']['text'])->toContain('Hello, Alice.')->toContain('Welcome, Bob.')
        ->and($this->record->refresh()->transcript)->toBe($originalText)->and($this->record->segments)->toBe($originalSegments)
        ->and($this->record->status)->toBe('complete')->and($this->record->export_revision)->toBe(0);
    $this->assertDatabaseCount('transcription_exports', 0);
    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer test-key') && $request['store'] === false
            && str_contains($request['input'], 'um hello alice') && $request['model'] === 'gpt-4.1-mini'
            && $request['text']['format']['strict'] === true;
    });
});

test('plain text cleanup remains a suggestion and does not invent speaker segments', function () {
    $this->record->update(['transcript' => 'uh this is the recording', 'segments' => []]);
    $this->providerResult = ['text' => 'This is the recording.'];
    $tool = submitTool($this, toolQuote($this));
    app(ProcessTranscriptTool::class)->handle($tool['id']);
    $this->getJson($this->base.'/'.$tool['id'])->assertOk()->assertJsonPath('result.text', 'This is the recording.')
        ->assertJsonPath('result.segments', []);
    expect($this->record->refresh()->transcript)->toBe('uh this is the recording')->and($this->record->segments)->toBe([]);
});

test('summaries persist key points and action items using the quoted model', function () {
    $this->providerResult = ['summary' => 'Alice and Bob reviewed the plan.', 'keyPoints' => ['The plan is ready.'],
        'actionItems' => ['Bob will send the plan.']];
    $quote = toolQuote($this, 'summary');
    $tool = submitTool($this, $quote);
    config(['openai.text_model' => 'future-model', 'billing.rates.summary.openai' => ['future-model' => ['unit' => '1000_characters', 'credits' => '900']]]);
    app(ProcessTranscriptTool::class)->handle($tool['id']);
    $this->getJson($this->base.'/'.$tool['id'])->assertOk()->assertJsonPath('operation', 'summary')->assertJsonPath('status', 'complete')
        ->assertJsonPath('result.summary', 'Alice and Bob reviewed the plan.')->assertJsonPath('result.keyPoints.0', 'The plan is ready.')
        ->assertJsonPath('result.actionItems.0', 'Bob will send the plan.');
    $this->assertDatabaseHas('usage_charges', ['transcript_tool_id' => $tool['id'], 'activity' => 'summary', 'model' => 'gpt-4.1-mini', 'status' => 'consumed']);
    Http::assertSent(fn ($request) => $request['model'] === 'gpt-4.1-mini');
    expect($this->record->refresh()->transcript)->toBe("um hello alice\nwelcome bob");
});

test('exhausted generation failure returns reserved credits exactly once', function () {
    $tool = submitTool($this, toolQuote($this));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429)]);
    $processor = app(ProcessTranscriptTool::class);
    expect(fn () => $processor->handle($tool['id']))->toThrow(BillingException::class);
    $event = OutboxEvent::where('event_type', 'TranscriptToolRequested')->sole();
    $job = new ProcessTranscriptToolJob($tool['id'], $event->id);
    $job->failed(new RuntimeException('Provider retries exhausted.'));
    $job->failed(new RuntimeException('Duplicate failure callback.'));
    $this->getJson($this->base.'/'.$tool['id'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('result', null);
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray(['available_units' => 10000, 'reserved_units' => 0])
        ->and(OutboxEvent::where('event_type', 'CreditsReturned')->count())->toBe(1);
    $this->assertDatabaseHas('usage_charges', ['transcript_tool_id' => $tool['id'], 'status' => 'released']);
    expect($this->record->refresh()->status)->toBe('complete')->and($event->refresh()->published_at)->not->toBeNull();
});

test('an interrupted worker resumes its saved result without another provider request', function () {
    $quote = toolQuote($this);
    $tool = submitTool($this, $quote);
    $saved = ['text' => 'Already generated.', 'segments' => []];
    TranscriptTool::findOrFail($tool['id'])->update(['status' => 'processing', 'result' => $saved]);
    $processor = app(ProcessTranscriptTool::class);
    $processor->handle($tool['id']);
    $processor->handle($tool['id']);
    $processor->fail($tool['id']);
    $this->getJson($this->base.'/'.$tool['id'])->assertOk()->assertJsonPath('status', 'complete')->assertJsonPath('result', $saved);
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray([
        'available_units' => 10000 - $quote['credit_units'], 'reserved_units' => 0]);
    $this->assertDatabaseHas('usage_charges', ['transcript_tool_id' => $tool['id'], 'status' => 'consumed']);
    expect(OutboxEvent::where('event_type', 'CreditsReturned')->count())->toBe(0);
    Http::assertNothingSent();
});

test('outbox queues one unique worker and acknowledges only after the saved result completes', function () {
    $tool = submitTool($this, toolQuote($this));
    $event = OutboxEvent::where('event_type', 'TranscriptToolRequested')->sole();
    $this->artisan('outbox:publish')->assertSuccessful();
    $this->artisan('outbox:publish')->assertSuccessful();
    Queue::assertPushed(ProcessTranscriptToolJob::class, 1);
    $job = Queue::pushed(ProcessTranscriptToolJob::class)->first();
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($event->refresh()->attempts)->toBe(1)->and($event->published_at)->toBeNull();
    app()->call([$job, 'handle']);
    expect($event->refresh()->published_at)->not->toBeNull();
    $this->getJson($this->base.'/'.$tool['id'])->assertOk()->assertJsonPath('status', 'complete');
    app()->call([$job, 'handle']);
    Http::assertSentCount(1);
});

test('a quote linked to a reused result still confirms after expiry and later transcript edits', function () {
    $originalQuote = toolQuote($this);
    $tool = submitTool($this, $originalQuote);
    app(ProcessTranscriptTool::class)->handle($tool['id']);
    $reusedQuote = toolQuote($this);
    $this->postJson($this->base, ['quote_id' => $reusedQuote['id']])->assertOk()->assertJsonPath('id', $tool['id']);
    $this->assertDatabaseHas('billing_quotes', ['id' => $reusedQuote['id'], 'status' => 'submitted', 'transcript_tool_id' => $tool['id']]);
    BillingQuote::findOrFail($reusedQuote['id'])->update(['expires_at' => now()->subHour()]);
    $this->postJson($this->base, ['quote_id' => $reusedQuote['id']])->assertOk()
        ->assertJsonPath('id', $tool['id'])->assertJsonPath('stale', false);
    $this->record->update(['transcript' => 'A subsequent edit.', 'segments' => []]);
    $this->postJson($this->base, ['quote_id' => $reusedQuote['id']])->assertOk()
        ->assertJsonPath('id', $tool['id'])->assertJsonPath('status', 'complete')->assertJsonPath('stale', true);
    $this->assertDatabaseCount('transcript_tools', 1);
    $this->assertDatabaseCount('usage_charges', 1);
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray([
        'available_units' => 10000 - $originalQuote['credit_units'], 'reserved_units' => 0]);
    Http::assertSentCount(1);
});

test('cleanup retries resume saved batches without charging twice or changing speaker timing', function () {
    $segments = $this->record->segments;
    $segments[0]['text'] = str_repeat('alpha ', 750);
    $segments[1]['text'] = str_repeat('bravo ', 750);
    $originalText = implode("\n", array_column($segments, 'text'));
    $this->record->update(['transcript' => $originalText, 'segments' => $segments]);
    $quote = toolQuote($this);
    $tool = submitTool($this, $quote);
    $calls = 0;
    $inputs = [];
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/responses' => function ($request) use (&$calls, &$inputs) {
        $inputs[] = $request['input'];
        $calls++;
        if ($calls === 2) {
            return Http::response(['error' => ['code' => 'temporarily_unavailable']], 503);
        }
        $text = $calls === 1 ? 'First cleaned text.' : 'Second cleaned text.';

        return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => json_encode(['texts' => [$text]], JSON_THROW_ON_ERROR)]]]]]);
    }]);
    $processor = app(ProcessTranscriptTool::class);
    expect(fn () => $processor->handle($tool['id']))->toThrow(BillingException::class);
    $saved = TranscriptTool::findOrFail($tool['id']);
    expect($saved->status)->toBe('processing')->and($saved->result)->toBeNull()
        ->and($saved->progress['batches'])->toHaveCount(1)->and($saved->progress['batches'][0])->toBe(['First cleaned text.'])
        ->and(app(CreditService::class)->balance($this->user->id))->toMatchArray([
            'available_units' => 10000 - $quote['credit_units'], 'reserved_units' => $quote['credit_units']]);
    $processor->handle($tool['id']);
    $processor->handle($tool['id']);
    $response = $this->getJson($this->base.'/'.$tool['id'])->assertOk()->assertJsonPath('status', 'complete')
        ->assertJsonPath('result.segments.0.text', 'First cleaned text.')->assertJsonPath('result.segments.1.text', 'Second cleaned text.')->json();
    foreach ($segments as $index => $segment) {
        expect($response['result']['segments'][$index])->toMatchArray(array_diff_key($segment, ['text' => true]));
    }
    expect($inputs[0])->not->toBe($inputs[1])->and($inputs[1])->toBe($inputs[2])
        ->and($this->record->refresh()->transcript)->toBe($originalText)->and($this->record->segments)->toBe($segments)
        ->and($saved->refresh()->progress['batches'])->toHaveCount(2)
        ->and(app(CreditService::class)->balance($this->user->id))->toMatchArray([
            'available_units' => 10000 - $quote['credit_units'], 'reserved_units' => 0]);
    $this->assertDatabaseCount('usage_charges', 1);
    $this->assertDatabaseHas('usage_charges', ['transcript_tool_id' => $tool['id'], 'status' => 'consumed']);
    expect(OutboxEvent::where('event_type', 'CreditsReturned')->count())->toBe(0);
    Http::assertSentCount(3);
});

test('plain Unicode cleanup joins multiple pieces from one request without adding whitespace', function (string $text) {
    $this->record->update(['transcript' => $text, 'segments' => []]);
    $tool = submitTool($this, toolQuote($this));
    $requestedPieces = [];
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['api.openai.com/v1/responses' => function ($request) use (&$requestedPieces) {
        $requestedPieces = json_decode($request['input'], true, flags: JSON_THROW_ON_ERROR)['segments'];

        return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => json_encode(['texts' => array_map('trim', $requestedPieces)], JSON_THROW_ON_ERROR)]]]]]);
    }]);
    app(ProcessTranscriptTool::class)->handle($tool['id']);
    $this->getJson($this->base.'/'.$tool['id'])->assertOk()->assertJsonPath('status', 'complete')
        ->assertJsonPath('result.text', $text)->assertJsonPath('result.segments', []);
    expect($requestedPieces)->toHaveCount(2)->and($this->record->refresh()->transcript)->toBe($text)
        ->and($this->record->segments)->toBe([]);
    Http::assertSentCount(1);
})->with([
    'unbroken Unicode word' => [str_repeat('界', 2200)],
    'Unicode paragraphs' => [str_repeat('界', 1000)."\n\n".str_repeat('語', 1200)],
]);
