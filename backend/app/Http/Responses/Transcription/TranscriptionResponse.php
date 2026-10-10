<?php

namespace App\Http\Responses\Transcription;

use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Entities\TranscriptTool;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class TranscriptionResponse extends ApiResponse
{
    public function data(TranscriptTool $record, string $fingerprint): array
    {
        return ['id' => $record->id, 'transcriptionId' => $record->transcriptionId, 'operation' => $record->operation,
            'status' => $record->status, 'result' => $record->result, 'failureReason' => $record->failureReason,
            'createdAt' => $record->createdAt, 'stale' => $record->sourceHash !== $fingerprint];
    }

    public function quoteData(array $quote, array $balance): array
    {
        return [
            'id' => $quote['id'], 'status' => $quote['status'], 'quantity' => $quote['quantity'], 'credit_units' => $quote['credit_units'],
            'expires_at' => $quote['expires_at'], 'available_units' => $balance['available_units'],
            'enough_credits' => $balance['available_units'] >= $quote['credit_units'],
        ];
    }

    public function created(Transcription $record): JsonResponse
    {
        return $this->json(['id' => $record->id, 'status' => $record->status, 'message' => 'File queued for transcription.'], 202);
    }

    public function updated(Transcription $record): JsonResponse
    {
        return $this->json(['id' => $record->id, 'transcript' => $record->transcript, 'status' => $record->status, 'segments' => $record->segments]);
    }

    public function exports(Transcription $record): JsonResponse
    {
        return $this->json(['id' => $record->id, 'status' => $record->status], $record->status === 'processing' ? 202 : 200);
    }

    public function history(bool $configured, array $history, string $fingerprint): JsonResponse
    {
        return $this->json(['configured' => $configured, 'data' => array_map(fn (TranscriptTool $record): array => $this->data($record, $fingerprint), $history)]);
    }
}
