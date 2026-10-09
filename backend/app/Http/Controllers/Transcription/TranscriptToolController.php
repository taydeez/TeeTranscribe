<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Transcriber\Entities\TranscriptTool;
use App\Domain\Transcriber\Services\TranscriptToolService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TranscriptToolController extends Controller
{
    public function __construct(private readonly TranscriptToolService $service) {}

    public function index(Request $request, string $transcription): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $history = $this->service->history($transcription, $userId);
        $fingerprint = TranscriptToolService::fingerprint($this->service->source($transcription, $userId));

        return response()->json(['configured' => $this->service->configured(),
            'data' => array_map(fn (TranscriptTool $record): array => $this->data($record, $fingerprint), $history)]);
    }

    public function quote(Request $request, string $transcription, CreditService $credits): JsonResponse
    {
        $input = $request->validate(['operation' => ['required', 'in:cleanup,summary'], 'client_key' => ['required', 'uuid']]);
        $userId = (int) $request->user()->getAuthIdentifier();
        $quote = $this->service->quote($transcription, $userId, $input['operation'], $input['client_key']);
        $balance = $credits->balance($userId);

        return response()->json([
            'id' => $quote['id'], 'status' => $quote['status'], 'quantity' => $quote['quantity'], 'credit_units' => $quote['credit_units'],
            'expires_at' => $quote['expires_at'], 'available_units' => $balance['available_units'],
            'enough_credits' => $balance['available_units'] >= $quote['credit_units'],
        ]);
    }

    public function store(Request $request, string $transcription): JsonResponse
    {
        $input = $request->validate(['quote_id' => ['required', 'ulid']]);
        $userId = (int) $request->user()->getAuthIdentifier();
        $record = $this->service->submit($transcription, $userId, $input['quote_id']);

        return response()->json($this->data($record, TranscriptToolService::fingerprint($this->service->source($transcription, $userId))), $record->status === 'complete' ? 200 : 202);
    }

    public function show(Request $request, string $transcription, string $tool): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $record = $this->service->find($tool, $transcription, $userId);

        return response()->json($this->data($record, TranscriptToolService::fingerprint($this->service->source($transcription, $userId))));
    }

    private function data(TranscriptTool $record, string $fingerprint): array
    {
        return ['id' => $record->id, 'transcriptionId' => $record->transcriptionId, 'operation' => $record->operation,
            'status' => $record->status, 'result' => $record->result, 'failureReason' => $record->failureReason,
            'createdAt' => $record->createdAt, 'stale' => $record->sourceHash !== $fingerprint];
    }
}
