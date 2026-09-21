<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Transcription;

use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeepgramWebhookController
{
    public function __construct(private readonly OutboxService $outbox) {}

    public function __invoke(Request $request, string $transcription): Response
    {

        Log::info('Deepgram webhook received', ['transcription' => $transcription]);

        $data = $request->validate([
            'metadata.request_id' => ['required', 'string', 'max:255'],
            'metadata.duration' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'results.channels.0.alternatives.0.transcript' => ['present', 'nullable', 'string'],
        ]);

        try {
            DB::transaction(function () use ($data, $transcription): void {
                $record = Transcription::query()
                    ->whereKey($transcription)
                    ->where('provider_request_id', $data['metadata']['request_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($record->status !== 'pending') {
                    return;
                }

                $record->status = 'processing';
                $record->transcript = $data['results']['channels'][0]['alternatives'][0]['transcript'] ?? '';

                if (isset($data['metadata']['duration'])) {
                    $record->duration = (float) $data['metadata']['duration'];
                }

                if (! $record->save()) {
                    throw new RuntimeException('The transcription could not be moved to processing.');
                }

                $this->outbox->record(
                    eventKey: "transcription:{$record->id}:completed",
                    eventType: 'TranscriptionCompleted',
                    aggregateId: $record->id,
                    payload: ['transcription_id' => $record->id],
                );
            }, attempts: 3);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Transcription::query()
                ->whereKey($transcription)
                ->where('provider_request_id', $data['metadata']['request_id'])
                ->update(['status' => 'failed']);

            throw $exception;
        }

        return response()->noContent();
    }
}
