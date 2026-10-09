<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Dubbing\Contracts\DubbingSubtitleRendererInterface;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use RuntimeException;

final readonly class ProcessDubbingSubtitles
{
    public function __construct(private DubbingRepositoryInterface $records, private BillingRepositoryInterface $billing,
        private DubbingGatewayResolverInterface $providers, private DubbingSubtitleRendererInterface $renderer, private PrivacyCoordinatorInterface $privacy) {}

    public function handle(string $id): void
    {
        $this->privacy->exclusive('dubbing', $id, function () use ($id): void {
            if ($this->privacy->projectDeleted('dubbing', $id)) {
                return;
            }
            $this->process($id);
        });
    }

    private function process(string $id): void
    {
        $this->billing->exclusive('dubbing:subtitles:'.$id, function () use ($id): void {
            $record = $this->records->find($id);
            if ($record === null || ! $record->subtitlesEnabled || in_array($record->status, ['complete', 'failed'], true)) {
                return;
            }
            if ($record->providerCompletedAt === null || $record->videoStoragePath === null || $record->audioStoragePath === null) {
                throw new RuntimeException('The dubbed video is not ready for subtitles.');
            }
            $record = $this->records->update($id, ['subtitle_status' => 'processing']);
            $subtitles = $record->subtitleStoragePath !== null ? ['storage_path' => $record->subtitleStoragePath]
                : ($record->operation === 'subtitles' ? ['segments' => $record->translatedSubtitleSegments ?? []]
                    : $this->providers->resolve($record->provider)->subtitles($record));
            if ($this->privacy->projectDeleted('dubbing', $id)) {
                return;
            }
            $paths = $this->renderer->render($record, $subtitles);
            if ($this->privacy->projectDeleted('dubbing', $id)) {
                return;
            }
            $this->billing->transaction(fn () => $this->records->update($id, $paths + [
                'subtitle_status' => 'complete', 'status' => 'complete', 'failure_reason' => null]));
        });
    }

    public function fail(string $id): void
    {
        $this->billing->transaction(function () use ($id): void {
            $record = $this->records->find($id, lock: true);
            if ($record === null || ! $record->subtitlesEnabled || $record->status === 'complete') {
                return;
            }
            $this->records->update($id, ['subtitle_status' => 'failed', 'status' => 'failed',
                'failure_reason' => $record->operation === 'subtitles'
                    ? 'Your subtitles are ready, but the video could not be rendered. Retry download preparation without paying again.'
                    : 'Your dub is ready, but subtitles could not be added. Retry download preparation without paying for dubbing again.']);
        });
    }
}
