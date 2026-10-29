<?php

namespace App\Domain\Transcriber\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Contracts\TranscriptToolGatewayInterface;
use App\Domain\Transcriber\Contracts\TranscriptToolRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Entities\TranscriptTool;
use DateTimeImmutable;

final readonly class TranscriptToolService
{
    public function __construct(
        private TranscriptToolRepositoryInterface $tools,
        private TranscriptionRepositoryInterface $transcriptions,
        private TranscriptToolGatewayInterface $gateway,
        private BillingRepositoryInterface $billing,
        private BillingSettingsInterface $settings,
        private CreditService $credits,
    ) {}

    public function configured(): bool
    {
        foreach (['cleanup', 'summary'] as $operation) {
            try {
                $definition = $this->gateway->definition($operation);
                if (! $definition['configured']) {
                    continue;
                }
                $this->settings->rate($operation, 'openai', $definition['model']);

                return true;
            } catch (BillingException) {
                continue;
            }
        }

        return false;
    }

    public function source(string $id, int $userId): Transcription
    {
        $record = $this->transcriptions->find($id);
        if ($record === null || $record->userId !== $userId) {
            throw new BillingException('Transcript not found.', 404);
        }

        return $record;
    }

    public static function fingerprint(Transcription $record): string
    {
        return self::contentFingerprint($record->transcript, $record->segments);
    }

    private static function contentFingerprint(?string $text, array $segments): string
    {
        return hash('sha256', json_encode([$text, $segments], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<TranscriptTool> */
    public function history(string $id, int $userId): array
    {
        $this->source($id, $userId);

        return $this->tools->forTranscription($id, $userId);
    }

    public function find(string $id, string $transcriptionId, int $userId): TranscriptTool
    {
        $this->source($transcriptionId, $userId);
        $record = $this->tools->find($id, $userId);
        if ($record === null || $record->transcriptionId !== $transcriptionId) {
            throw new BillingException('Transcript result not found.', 404);
        }

        return $record;
    }

    /** @param array{transcript: string, segments: list<array{text: string, speaker?: string|null}>}|null $draft */
    public function quote(string $id, int $userId, string $operation, string $clientKey, ?array $draft = null): array
    {
        if (! in_array($operation, ['cleanup', 'summary'], true)) {
            throw new BillingException('Choose cleanup or summary.', 422);
        }
        $record = $this->source($id, $userId);
        if (! in_array($record->status, ['complete', 'processing'], true) || trim($record->transcript ?? '') === '') {
            throw new BillingException('Wait for the transcript to finish before using these tools.', 409);
        }
        $text = $draft === null ? $record->transcript : trim($draft['transcript']);
        $segments = $draft === null ? $record->segments : $this->draftSegments($record, $draft);
        if ($text === '' || mb_strlen($text, 'UTF-8') > 50000 || count($segments) > 2500) {
            throw new BillingException('These tools currently support up to 50,000 characters and 2,500 speaker segments.', 422);
        }
        if ($operation === 'cleanup') {
            TranscriptCleanupBatches::plan($text, $segments);
        }
        $source = ['transcription_id' => $id, 'source_hash' => self::contentFingerprint($text, $segments),
            'saved_hash' => self::fingerprint($record), 'text' => $text, 'segments' => $segments];

        return $this->billing->exclusive('quote:'.$userId.':'.$clientKey, function () use ($source, $userId, $operation, $clientKey): array {
            $existing = $this->billing->quoteByClientKey($userId, $clientKey);
            if ($existing !== null) {
                if ($existing['activity'] !== $operation || $existing['request_source'] != $source) {
                    throw new BillingException('This quote reference belongs to a different request.', 409);
                }

                return $existing;
            }
            $definition = $this->gateway->definition($operation);
            if (! $definition['configured']) {
                throw new BillingException('Transcript tools are not configured yet.', 503);
            }
            $rate = $this->settings->rate($operation, 'openai', $definition['model']);
            $quantity = mb_strlen($source['text'], 'UTF-8');

            return $this->billing->createQuote([
                'user_id' => $userId, 'client_key' => $clientKey, 'activity' => $operation, 'provider' => 'openai', 'model' => $definition['model'],
                'source' => $source, 'request_source' => $source, 'rate' => $rate, 'quantity' => $quantity,
                'credit_units' => CreditMath::prorate($rate['credit_units'], $quantity, $rate['unit_length']),
                'status' => 'ready', 'expires_at' => $this->settings->quoteExpiresAt(),
            ]);
        });
    }

    public function submit(string $transcriptionId, int $userId, string $quoteId): TranscriptTool
    {
        $quote = $this->billing->quote($quoteId, $userId) ?? throw new BillingException('Quote not found.', 404);
        if (! in_array($quote['activity'], ['cleanup', 'summary'], true) || ($quote['source']['transcription_id'] ?? null) !== $transcriptionId) {
            throw new BillingException('This quote is not for this transcript.', 422);
        }
        $lock = 'transcript-tool:submit:'.$userId.':'.$transcriptionId.':'.$quote['activity'].':'.$quote['source']['source_hash'];

        return $this->billing->exclusive($lock, fn (): TranscriptTool => $this->billing->transaction(function () use ($transcriptionId, $userId, $quoteId): TranscriptTool {
            $quote = $this->billing->quote($quoteId, $userId, lock: true) ?? throw new BillingException('Quote not found.', 404);
            if ($quote['transcript_tool_id'] !== null) {
                return $this->find($quote['transcript_tool_id'], $transcriptionId, $userId);
            }
            if ($quote['status'] !== 'ready' || new DateTimeImmutable($quote['expires_at']) <= new DateTimeImmutable) {
                throw new BillingException('This quote has expired. Check the price again.', 422);
            }
            $source = $this->source($transcriptionId, $userId);
            if (self::fingerprint($source) !== ($quote['source']['saved_hash'] ?? $quote['source']['source_hash'])) {
                throw new BillingException('The transcript changed. Check the price again.', 409);
            }
            $existing = $this->tools->reusable($transcriptionId, $userId, $quote['activity'], $quote['source']['source_hash']);
            if ($existing !== null) {
                $this->billing->updateQuote($quoteId, ['status' => 'submitted', 'transcript_tool_id' => $existing->id]);

                return $existing;
            }
            $record = $this->tools->create([
                'transcription_id' => $transcriptionId, 'user_id' => $userId, 'operation' => $quote['activity'], 'status' => 'pending',
                'source_text' => $quote['source']['text'], 'source_segments' => $quote['source']['segments'], 'source_hash' => $quote['source']['source_hash'],
                'provider' => $quote['provider'], 'model' => $quote['model'],
            ]);
            $this->credits->reserve($userId, $quote, $record->id);
            $this->billing->updateQuote($quoteId, ['status' => 'submitted', 'transcript_tool_id' => $record->id]);
            $this->billing->enqueue('transcript-tool:'.$record->id.':requested', 'TranscriptToolRequested', $record->id, []);

            return $record;
        }));
    }

    /** @param array{transcript: string, segments: list<array{text: string, speaker?: string|null}>} $draft */
    private function draftSegments(Transcription $record, array $draft): array
    {
        if ($draft['segments'] === []) {
            return [];
        }
        if (count($draft['segments']) !== count($record->segments)) {
            throw new BillingException('Speaker segments must match the original transcript.', 422);
        }
        $segments = $record->segments;
        foreach ($segments as $index => &$segment) {
            $segment['text'] = trim($draft['segments'][$index]['text']);
            $segment['speaker'] = $draft['segments'][$index]['speaker'] ?? null;
        }
        unset($segment);
        if (implode("\n", array_column($segments, 'text')) !== trim($draft['transcript'])) {
            throw new BillingException('The draft text must match its speaker segments.', 422);
        }

        return $segments;
    }
}
