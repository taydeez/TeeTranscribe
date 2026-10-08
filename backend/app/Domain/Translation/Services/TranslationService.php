<?php

namespace App\Domain\Translation\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Translation\Contracts\TranslationRepositoryInterface;
use App\Domain\Translation\Entities\Translation;
use DateTimeImmutable;

final readonly class TranslationService
{
    public function __construct(
        private TranslationRepositoryInterface $translations,
        private BillingRepositoryInterface $billing,
        private BillingSettingsInterface $settings,
        private CreditService $credits,
        private TranslationLanguages $languages,
    ) {}

    public function quote(int $userId, array $input, string $key): array
    {
        $source = $this->source($userId, $input);

        return $this->billing->exclusive('quote:'.$userId.':'.$key, function () use ($userId, $source, $key): array {
            $existing = $this->billing->quoteByClientKey($userId, $key);
            if ($existing !== null) {
                if ($existing['activity'] !== 'translation' || $existing['request_source'] != $source) {
                    throw new BillingException('This quote reference belongs to a different request.', 409);
                }

                return $existing;
            }
            $this->languages->validate($source['source_language'], $source['target_language']);
            $rate = $this->settings->rate('translation', 'google', 'nmt');
            $quantity = mb_strlen($source['text'], 'UTF-8');

            return $this->billing->createQuote([
                'user_id' => $userId, 'client_key' => $key, 'activity' => 'translation', 'provider' => 'google', 'model' => 'nmt',
                'rate' => $rate, 'source' => $source, 'request_source' => $source, 'quantity' => $quantity,
                'credit_units' => CreditMath::prorate($rate['credit_units'], $quantity, $rate['unit_length']),
                'status' => 'ready', 'expires_at' => $this->settings->quoteExpiresAt(),
            ]);
        });
    }

    public function submit(int $userId, string $quoteId): Translation
    {
        return $this->billing->transaction(function () use ($userId, $quoteId): Translation {
            $quote = $this->billing->quote($quoteId, $userId, lock: true) ?? throw new BillingException('Quote not found.', 404);
            if ($quote['activity'] !== 'translation') {
                throw new BillingException('This quote is not for translation.', 422);
            }
            if ($quote['translation_id'] !== null) {
                return $this->find($quote['translation_id'], $userId);
            }
            if ($quote['status'] !== 'ready' || new DateTimeImmutable($quote['expires_at']) <= new DateTimeImmutable) {
                throw new BillingException('This quote has expired. Check the price again.', 422);
            }
            $source = $quote['source'];
            if ($source['transcription_id'] !== null && $this->translations->sourceForUser($source['transcription_id'], $userId) === null) {
                throw new BillingException('Source transcript not found.', 404);
            }
            $record = $this->translations->create([
                'user_id' => $userId, 'transcription_id' => $source['transcription_id'], 'name' => $source['name'],
                'source_text' => $source['text'], 'source_segments' => $source['segments'],
                'source_language' => $source['source_language'], 'target_language' => $source['target_language'], 'status' => 'pending',
            ]);
            $this->credits->reserve($userId, $quote, $record->id);
            $this->billing->updateQuote($quoteId, ['status' => 'submitted', 'translation_id' => $record->id]);
            $this->billing->enqueue('translation:'.$record->id.':submitted', 'TranslationSubmitted', $record->id, ['revision' => 0]);

            return $record;
        });
    }

    public function find(string $id, int $userId): Translation
    {
        return $this->translations->find($id, $userId) ?? throw new BillingException('Translation not found.', 404);
    }

    public function history(int $userId, int $page, int $perPage): array
    {
        return $this->translations->paginateForUser($userId, $page, $perPage);
    }

    public function edit(string $id, int $userId, string $text, ?array $segments): Translation
    {
        return $this->billing->transaction(function () use ($id, $userId, $text, $segments): Translation {
            $record = $this->translations->find($id, $userId, lock: true) ?? throw new BillingException('Translation not found.', 404);
            if ($record->translatedText === null) {
                throw new BillingException('This translation is not ready for editing.', 409);
            }
            $updatedSegments = [];
            if ($segments !== null) {
                if ($record->segments === [] || count($segments) !== count($record->segments)) {
                    throw new BillingException('Translation segments must match the saved result.', 422);
                }
                $updatedSegments = $record->segments;
                foreach ($updatedSegments as $index => &$segment) {
                    $segment['text'] = trim($segments[$index]['text']);
                    $segment['speaker'] = $segments[$index]['speaker'] ?? null;
                }
                unset($segment);
                $text = implode("\n", array_column($updatedSegments, 'text'));
            }
            $changed = $record->translatedText !== $text || $record->segments !== $updatedSegments;
            if (! $changed && $record->status !== 'failed') {
                return $record;
            }
            $revision = $record->exportRevision + ($changed ? 1 : 0);
            $updated = $this->translations->update($id, [
                'translated_text' => $text, 'segments' => $updatedSegments, 'status' => 'processing',
                'export_revision' => $revision, 'exports' => [], 'failure_reason' => null,
            ]);
            $this->translations->enqueueExports($id, $revision);

            return $updated;
        });
    }

    private function source(int $userId, array $input): array
    {
        $transcriptionId = $input['transcription_id'] ?? null;
        $original = $transcriptionId !== null ? ($this->translations->sourceForUser($transcriptionId, $userId) ?? throw new BillingException('Source transcript not found.', 404)) : null;
        $text = trim($input['text'] ?? $original['text'] ?? '');
        $segments = $original !== null && $text === trim($original['text'] ?? '') ? $original['segments'] : [];
        if (isset($input['segments'])) {
            if ($original === null || $original['segments'] === [] || count($input['segments']) !== count($original['segments'])) {
                throw new BillingException('Source segments must match the owned transcript.', 422);
            }
            $segments = $original['segments'];
            foreach ($segments as $index => &$segment) {
                $segment['text'] = trim($input['segments'][$index]['text']);
                $segment['speaker'] = $input['segments'][$index]['speaker'] ?? null;
            }
            unset($segment);
            $text = implode("\n", array_column($segments, 'text'));
        }
        if ($text === '' || mb_strlen($text, 'UTF-8') > 50000) {
            throw new BillingException('Enter between 1 and 50,000 characters to translate.', 422);
        }

        return [
            'text' => $text, 'transcription_id' => $transcriptionId,
            'name' => trim($input['name'] ?? '') ?: ($original['name'] ?? 'Translation'),
            'source_language' => $input['source_language'] ?? null, 'target_language' => $input['target_language'],
            'segments' => $segments,
        ];
    }
}
