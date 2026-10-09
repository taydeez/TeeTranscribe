<?php

namespace App\Http\Responses\Translation;

use App\Domain\Translation\Contracts\TranslationExportStorageInterface;
use App\Domain\Translation\Entities\Translation;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class TranslationResponse extends ApiResponse
{
    public function __construct(private readonly TranslationExportStorageInterface $exports) {}

    public function data(Translation $record, bool $summary = false): array
    {
        $data = [
            'id' => $record->id, 'name' => $record->name, 'status' => $record->status,
            'sourceLanguage' => $record->sourceLanguage, 'detectedLanguage' => $record->detectedLanguage,
            'targetLanguage' => $record->targetLanguage, 'transcriptionId' => $record->transcriptionId,
            'createdAt' => $record->createdAt, 'failureReason' => $record->failureReason,
            'folderId' => $record->folderId,
        ];
        if (! $summary) {
            $data += ['sourceText' => $record->sourceText, 'translatedText' => $record->translatedText, 'segments' => $record->segments,
                'exportRevision' => $record->exportRevision,
                'exports' => array_map(fn ($export) => [
                    'format' => $export['format'], 'variant' => $export['variant'], 'status' => $export['status'],
                    'downloadUrl' => $this->exports->downloadUrl($export, $record->name),
                ], $record->exports),
            ];
        }

        return $data;
    }

    public function languages(array $languages): JsonResponse
    {
        return $this->json(['data' => $languages]);
    }

    public function quoteData(array $quote, array $balance): array
    {
        return [
            'id' => $quote['id'], 'status' => $quote['status'], 'quantity' => $quote['quantity'], 'credit_units' => $quote['credit_units'],
            'expires_at' => $quote['expires_at'], 'available_units' => $balance['available_units'],
            'enough_credits' => $balance['available_units'] >= $quote['credit_units'],
        ];
    }

    public function store(Translation $record): JsonResponse
    {
        return $this->json($this->data($record), $record->status === 'complete' ? 200 : 202);
    }

    public function history(array $history): JsonResponse
    {
        $history['data'] = array_map(fn (Translation $record): array => $this->data($record, summary: true), $history['data']);

        return $this->json($history);
    }
}
