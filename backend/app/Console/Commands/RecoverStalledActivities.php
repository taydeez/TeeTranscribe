<?php

namespace App\Console\Commands;

use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Services\ProcessTranscriptTool;
use App\Domain\Translation\Services\ProcessTranslation;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptTool;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('activities:recover {--limit=100 : Maximum stalled tasks per activity}')]
#[Description('Recover stalled tasks and free customer concurrency capacity')]
class RecoverStalledActivities extends Command
{
    public function handle(BillingConfigurationInterface $settings, PrivacyCoordinatorInterface $privacy): int
    {
        $cutoff = now()->subHours($settings->current()['processing_timeout_hours']);
        $limit = max(1, min(1000, (int) $this->option('limit')));
        foreach (['transcription' => Transcription::class, 'translation' => Translation::class, 'dubbing' => Dubbing::class, 'transcript_tool' => TranscriptTool::class] as $activity => $model) {
            $records = $model::whereIn('status', ['pending', 'processing'])->where('updated_at', '<=', $cutoff)->orderBy('updated_at')->limit($limit)->get();
            foreach ($records as $record) {
                try {
                    $scope = $activity === 'transcript_tool' ? 'transcription' : $activity;
                    $scopeId = $activity === 'transcript_tool' ? $record->transcription_id : $record->id;
                    $privacy->exclusive($scope, $scopeId, function () use ($record, $activity, $cutoff): void {
                        $current = $record->fresh();
                        if ($current === null || ! in_array($current->status, ['pending', 'processing'], true) || $current->updated_at->gt($cutoff)) {
                            return;
                        }
                        match ($activity) {
                            'transcription' => app(TranscriptionOutcomePublisher::class)->failed($current->id),
                            'translation' => app(ProcessTranslation::class)->fail($current->id, $current->export_revision),
                            'dubbing' => app(ProcessDubbing::class)->fail($current->id),
                            'transcript_tool' => app(ProcessTranscriptTool::class)->fail($current->id),
                        };
                        Log::warning('Stalled activity recovered.', ['activity' => $activity, 'resource_id' => $current->id, 'user_id' => $current->user_id]);
                    });
                } catch (Throwable $exception) {
                    Log::warning('Stalled activity recovery deferred.', ['activity' => $activity, 'resource_id' => $record->id, 'exception_type' => $exception::class]);
                }
            }
        }

        return self::SUCCESS;
    }
}
