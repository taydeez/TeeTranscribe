<?php

namespace App\Http\Controllers\Translation;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Translation\Contracts\TranslationExportStorageInterface;
use App\Domain\Translation\Entities\Translation;
use App\Domain\Translation\Services\TranslationLanguages;
use App\Domain\Translation\Services\TranslationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TranslationController extends Controller
{
    public function __construct(private readonly TranslationService $service, private readonly TranslationExportStorageInterface $exports) {}

    public function languages(TranslationLanguages $languages): JsonResponse
    {
        return response()->json(['data' => $languages->all()]);
    }

    public function quote(Request $request, CreditService $credits): JsonResponse
    {
        $input = $request->validate([
            'client_key' => ['required', 'uuid'], 'text' => ['nullable', 'required_without:transcription_id', 'string', 'max:50000'],
            'transcription_id' => ['nullable', 'ulid'], 'name' => ['nullable', 'string', 'max:255'],
            'source_language' => ['nullable', 'string', 'max:20'], 'target_language' => ['required', 'string', 'max:20'],
            'segments' => ['sometimes', 'array', 'list', 'min:1', 'max:20000'],
            'segments.*.text' => ['required', 'string', 'max:100000'], 'segments.*.speaker' => ['nullable', 'string', 'max:100'],
        ]);
        $id = (int) $request->user()->getAuthIdentifier();
        $quote = $this->service->quote($id, $input, $input['client_key']);
        $balance = $credits->balance($id);

        return response()->json([
            'id' => $quote['id'], 'status' => $quote['status'], 'quantity' => $quote['quantity'], 'credit_units' => $quote['credit_units'],
            'expires_at' => $quote['expires_at'], 'available_units' => $balance['available_units'],
            'enough_credits' => $balance['available_units'] >= $quote['credit_units'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['quote_id' => ['required', 'ulid']]);
        $record = $this->service->submit((int) $request->user()->getAuthIdentifier(), $input['quote_id']);

        return response()->json($this->data($record), $record->status === 'complete' ? 200 : 202);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $history = $this->service->history((int) $request->user()->getAuthIdentifier(), (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 10));
        $history['data'] = array_map(fn (Translation $record) => $this->data($record, summary: true), $history['data']);

        return response()->json($history);
    }

    public function show(Request $request, string $translation): JsonResponse
    {
        return response()->json($this->data($this->service->find($translation, (int) $request->user()->getAuthIdentifier())));
    }

    public function update(Request $request, string $translation): JsonResponse
    {
        $input = $request->validate([
            'translated_text' => ['required', 'string', 'max:200000'],
            'segments' => ['sometimes', 'array', 'list', 'min:1', 'max:20000'],
            'segments.*.text' => ['required', 'string', 'max:100000'], 'segments.*.speaker' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->data($this->service->edit($translation, (int) $request->user()->getAuthIdentifier(), $input['translated_text'], $input['segments'] ?? null)));
    }

    private function data(Translation $record, bool $summary = false): array
    {
        $data = [
            'id' => $record->id, 'name' => $record->name, 'status' => $record->status,
            'sourceLanguage' => $record->sourceLanguage, 'detectedLanguage' => $record->detectedLanguage,
            'targetLanguage' => $record->targetLanguage, 'transcriptionId' => $record->transcriptionId,
            'createdAt' => $record->createdAt, 'failureReason' => $record->failureReason,
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
}
