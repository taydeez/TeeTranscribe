<?php

namespace App\Domain\Translation\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Translation\Contracts\TranslationExportStorageInterface;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;
use App\Domain\Translation\Contracts\TranslationRepositoryInterface;

final readonly class ProcessTranslation
{
    public function __construct(
        private TranslationRepositoryInterface $translations,
        private TranslationGatewayInterface $gateway,
        private TranslationExportStorageInterface $exports,
        private BillingRepositoryInterface $billing,
        private CreditService $credits,
    ) {}

    public function handle(string $id): ?int
    {
        return $this->billing->exclusive('translation:processing:'.$id, function () use ($id): ?int {
            $record = $this->billing->transaction(function () use ($id) {
                $record = $this->translations->find($id, lock: true);
                if ($record === null || $record->status === 'complete' || ($record->status === 'failed' && $record->translatedText === null)) {
                    return $record;
                }

                return $this->translations->update($id, ['status' => 'processing', 'failure_reason' => null]);
            });
            if ($record === null) {
                return 0;
            }
            if ($record->status === 'complete' || ($record->status === 'failed' && $record->translatedText === null)) {
                return $record->exportRevision;
            }
            $revision = $record->exportRevision;
            if ($record->translatedText === null) {
                $source = $record->sourceSegments;
                $texts = $source === [] ? [$record->sourceText] : array_column($source, 'text');
                $result = $this->gateway->translate($texts, $record->sourceLanguage, $record->targetLanguage);
                $segments = $source;
                foreach ($segments as $index => &$segment) {
                    $segment['text'] = $result['texts'][$index];
                }
                unset($segment);
                $record = $this->billing->transaction(function () use ($id, $result, $segments) {
                    $this->translations->find($id, lock: true);
                    $record = $this->translations->update($id, [
                        'translated_text' => implode("\n", $result['texts']), 'segments' => $segments,
                        'detected_language' => $result['detected_language'],
                    ]);
                    $this->credits->consumeTranslation($id);

                    return $record;
                });
            }
            try {
                $exports = $this->exports->generate($record);
            } catch (\Throwable $exception) {
                if ($this->translations->find($id)?->exportRevision !== $revision) {
                    return null;
                }
                throw $exception;
            }

            return $this->billing->transaction(function () use ($id, $revision, $exports): ?int {
                $current = $this->translations->find($id, lock: true);
                if ($current === null || $current->exportRevision !== $revision) {
                    return null;
                }
                $this->translations->update($id, ['exports' => $exports, 'status' => 'complete', 'failure_reason' => null]);

                return $revision;
            });
        });
    }

    public function fail(string $id, int $revision): void
    {
        $this->billing->transaction(function () use ($id, $revision): void {
            $record = $this->translations->find($id, lock: true);
            if ($record === null || $record->status === 'complete' || $record->exportRevision !== $revision) {
                return;
            }
            $this->translations->update($id, [
                'status' => 'failed',
                'failure_reason' => $record->translatedText === null ? 'Translation failed. Your reserved credits have been returned.' : 'The translation is saved, but downloads could not be generated. Retry downloads below.',
            ]);
            if ($record->translatedText === null) {
                $this->credits->releaseTranslation($id);
            }
        });
    }
}
