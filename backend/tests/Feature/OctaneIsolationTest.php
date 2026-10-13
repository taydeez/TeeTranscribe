<?php

use App\Infrastructure\Observability\OperationalLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Octane\Events\RequestTerminated;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

test('successive Octane requests do not reuse the previous project logging context', function () {
    config(['observability.enabled' => true]);
    Http::preventStrayRequests();
    Http::fake(['api.deepgram.com/*' => Http::response(['request_id' => 'provider-request'])]);

    app(OperationalLogger::class)->scope = ['transcription_id' => 'first-customer-project'];
    Http::post('https://api.deepgram.com/v1/listen');
    Event::dispatch(new RequestTerminated($this->app, $this->app, Request::create('/first'), new Response));

    expect(app(OperationalLogger::class)->scope)->toBe([]);
    Http::post('https://api.deepgram.com/v1/listen');
    $rows = DB::table('operational_logs')->where('message', 'Provider HTTP response received.')->orderBy('created_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->operation_id)->toBe('first-customer-project')
        ->and($rows[1]->operation_id)->toBeNull()
        ->and($rows[1]->context)->not->toContain('first-customer-project');
});
