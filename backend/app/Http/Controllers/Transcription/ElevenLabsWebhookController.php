<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Services\TimedTranscriptSegments;
use App\Http\Controllers\Controller;
use App\Infrastructure\AI\Transcriber\TranscriptionCompletion;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ElevenLabsWebhookController extends Controller
{
    public function __invoke(Request $request, TranscriptionCompletion $completion): Response
    {
        $this->authenticate($request);
        if (! in_array($request->input('type'), ['speech_to_text_transcription', 'speech_to_text_transcription_failed'], true)) {
            return response()->noContent();
        }
        $data = $request->validate([
            'data.request_id' => ['required', 'string', 'max:255'],
            'data.webhook_metadata.transcription_id' => ['required', 'string', 'size:26'],
            'data.transcription' => ['sometimes', 'array'],
            'data.transcription.text' => ['sometimes', 'string'],
            'data.transcription.words' => ['sometimes', 'array'],
            'data.transcription.words.*.text' => ['present', 'nullable', 'string'],
            'data.transcription.words.*.type' => ['required', 'string'],
            'data.transcription.words.*.start' => ['nullable', 'numeric', 'min:0'],
            'data.transcription.words.*.end' => ['nullable', 'numeric', 'min:0'],
            'data.transcription.words.*.speaker_id' => ['nullable', 'string', 'max:255'],
        ])['data'];
        $id = $data['webhook_metadata']['transcription_id'];
        if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $id)) {
            return response()->noContent();
        }
        if ($request->input('type') === 'speech_to_text_transcription_failed') {
            app(PrivacyCoordinatorInterface::class)->exclusive('transcription', $id, function () use ($id, $data): void {
                if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $id)) {
                    return;
                }
                $this->fail($id, $data);
            });

            return response()->noContent();
        }
        $words = [];
        $speakers = [];
        foreach ($data['transcription']['words'] ?? [] as $word) {
            if ($word['type'] !== 'word' || ! isset($word['start'], $word['end'])) {
                continue;
            }
            $speaker = $word['speaker_id'] ?? null;
            if ($speaker !== null) {
                $speakers[$speaker] ??= 'Speaker '.(count($speakers) + 1);
            }
            $words[] = [
                'start' => (float) $word['start'], 'end' => (float) $word['end'],
                'text' => $word['text'] ?? '', 'speaker' => $speaker === null ? null : $speakers[$speaker],
            ];
        }
        $completion->complete($id, 'elevenlabs', $data['request_id'], $data['transcription']['text'] ?? '', TimedTranscriptSegments::fromWords($words));

        return response()->noContent();
    }

    private function fail(string $id, array $data): void
    {
        DB::transaction(function () use ($id, $data): void {
            $record = Transcription::where('provider', 'elevenlabs')->lockForUpdate()->findOrFail($id);
            abort_if($record->provider_request_id !== null && $record->provider_request_id !== $data['request_id'], 404);
            app(TranscriptionOutcomePublisher::class)->failed($id, pendingOnly: true);
        });

    }

    private function authenticate(Request $request): void
    {
        $secret = config('transcriber.elevenlabs.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);
        $parts = [];
        foreach (explode(',', $request->header('elevenlabs-signature', '')) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        $timestamp = $parts['t'] ?? '';
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 1800, 401);
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $parts['v0'] ?? ''), 401);
    }
}
