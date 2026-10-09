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
        $definition = $this->gateway->definition();
        if (! $definition['configured']) {
            return false;
        }
        try {
            foreach (['cleanup', 'summary'] as $operation) {
                $this->settings->rate($operation, 'openai', $definition['model']);
            }
        } catch (BillingException) {
            return false;
        }

        return true;
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
        return hash('sha256', json_encode([$record->transcript, $record->segments], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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

    public function quote(string $id, int $userId, string $operation, string $clientKey): array
    {
        if (! in_array($operation, ['cleanup', 'summary'], true)) {
            throw new BillingException('Choose cleanup or summary.', 422);
        }
        $record = $this->source($id, $userId);
        if (! in_array($record->status, ['complete', 'processing'], true) || trim($record->transcript ?? '') === '') {
            throw new BillingException('Wait for the transcript to finish before using these tools.', 409);
        }
        if (mb_strlen($record->transcript, 'UTF-8') > 50000 || count($record->segments) > 2500) {
            throw new BillingException('These tools currently support up to 50,000 characters and 2,500 speaker segments.', 422);
        }
        if ($operation === 'cleanup') {
            TranscriptCleanupBatches::plan($record->transcript, $record->segments);
        }
        $source = ['transcription_id' => $id, 'source_hash' => self::fingerprint($record),
            'text' => $record->transcript, 'segments' => $record->segments];

        return $this->billing->exclusive('quote:'.$userId.':'.$clientKey, function () use ($source, $userId, $operation, $clientKey): array {
            $existing = $this->billing->quoteByClientKey($userId, $clientKey);
            if ($existing !== null) {
                if ($existing['activity'] !== $operation || $existing['request_source'] != $source) {
                    throw new BillingException('This quote reference belongs to a different request.', 409);
                }

                return $existing;
            }
            $definition = $this->gateway->definition();
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
            if (self::fingerprint($source) !== $quote['source']['source_hash']) {
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
}
