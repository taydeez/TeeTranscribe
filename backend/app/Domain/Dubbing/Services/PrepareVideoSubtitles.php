<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Dubbing\Contracts\VideoSubtitleTranscriberInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;
use RuntimeException;

final readonly class PrepareVideoSubtitles
{
    public function __construct(private DubbingRepositoryInterface $records, private BillingRepositoryInterface $billing,
        private VideoSubtitleTranscriberInterface $transcriber, private TranslationGatewayInterface $translator,
        private DubbingMediaInterface $media, private CreditService $credits, private SubtitleDocument $document) {}

    /** Called under the dubbing processing lock. */
    public function handle(Dubbing $record): void
    {
        $record = $this->records->update($record->id, ['status' => 'processing']);
        if ($record->sourceSubtitleSegments === null) {
            $transcript = $this->transcriber->transcribe($record, $this->media->sourceUrl($record->sourceStoragePath));
            $segments = $transcript['segments'];
            $this->document->cues($segments, $record->durationMs / 1000);
            $record = $this->records->update($record->id, ['source_subtitle_segments' => $segments,
                'detected_source_language' => $transcript['detected_language']]);
        }
        if ($record->translatedSubtitleSegments === null) {
            $source = $this->translationCode($record->sourceLanguage ?? $record->detectedSourceLanguage);
            $segments = $record->sourceSubtitleSegments;
            $translated = $source === $record->targetLanguage ? array_column($segments, 'text')
                : $this->translator->translate(array_column($segments, 'text'), $source, $record->targetLanguage)['texts'];
            if (! array_is_list($translated) || count($translated) !== count($segments)) {
                throw new RuntimeException('The subtitle translation is incomplete.');
            }
            foreach ($segments as $index => &$segment) {
                $segment['text'] = $translated[$index];
            }
            unset($segment);
            $this->document->cues($segments, $record->durationMs / 1000);
            $record = $this->billing->transaction(function () use ($record, $segments): Dubbing {
                $updated = $this->records->update($record->id, ['translated_subtitle_segments' => $segments,
                    'provider_completed_at' => (new \DateTimeImmutable)->format(DATE_ATOM)]);
                $this->credits->consumeDubbing($record->id);

                return $updated;
            });
        }
        if ($record->videoStoragePath === null || $record->audioStoragePath === null) {
            $record = $this->records->update($record->id, $this->media->prepareOriginal($record));
        }
        $this->billing->transaction(function () use ($record): void {
            $this->records->update($record->id, ['subtitle_status' => 'pending', 'failure_reason' => null]);
            $this->records->enqueueSubtitles($record->id);
        });
    }

    private function translationCode(?string $source): ?string
    {
        return match ($source) {
            null => null, 'zh', 'zh-CN', 'zh-Hans' => 'zh-CN', 'zh-TW', 'zh-Hant' => 'zh-TW',
            'zh-HK' => 'yue', 'no' => 'no', 'nl-BE' => 'nl', default => explode('-', $source)[0],
        };
    }
}
