<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Billing\Contracts\ActivityRepositoryInterface;
use App\Domain\Billing\Entities\ActivityTask;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Transcriber\Events\TranscriptionFailed;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptTool;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class EloquentActivityRepository implements ActivityRepositoryInterface
{
    public function find(string $type, string $id, ?int $userId = null, bool $lock = false): ?ActivityTask
    {
        $record = $this->model($type)::query()->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($id);

        return $record === null ? null : $this->entity($type, $record);
    }

    public function active(string $type): iterable
    {
        foreach ($this->model($type)::query()->whereIn('status', ['pending', 'processing'])->lazyById(200) as $record) {
            yield $this->entity($type, $record);
        }
    }

    public function stop(ActivityTask $task, string $reason): ActivityTask
    {
        $record = $this->model($task->type)::query()->lockForUpdate()->findOrFail($task->id);
        $message = $reason === 'cancelled' ? 'Cancelled by you.' : 'Processing exceeded its time limit.';
        $record->forceFill(['status' => 'failed', 'cancelled_at' => $reason === 'cancelled' ? now() : null,
            'failure_reason' => $message.' Any credits still reserved for this task have been returned.'])->save();
        if ($task->type === 'transcription') {
            $record->exports()->where('status', 'pending')->update(['status' => 'failed', 'failure_reason' => $message]);
            DB::afterCommit(fn () => Event::dispatch(new TranscriptionFailed($task->id)));
        }
        OutboxEvent::query()->where('aggregate_id', $task->id)->whereNull('published_at')->update(['published_at' => now()]);

        return $this->entity($task->type, $record);
    }

    private function model(string $type): string
    {
        return match ($type) {
            'transcription' => Transcription::class,
            'translation' => Translation::class,
            'dubbing' => Dubbing::class,
            'transcript_tool' => TranscriptTool::class,
            default => throw new BillingException('Task not found.', 404),
        };
    }

    private function entity(string $type, Model $record): ActivityTask
    {
        $activity = match ($type) {
            'dubbing' => $record->operation === 'subtitles' ? 'subtitles' : ($record->media_type === 'audio' ? 'audio_dubbing' : 'video_dubbing'),
            'transcript_tool' => $record->operation,
            default => $type,
        };
        $start = $record->activity_started_at ?? ($record->status === 'pending' ? $record->created_at : $record->updated_at);

        return new ActivityTask($type, $record->id, $record->user_id, $activity, $record->provider, $record->status,
            $type === 'dubbing' ? $record->duration_ms / 1000 : (float) ($record->duration ?? 0),
            new \DateTimeImmutable((string) $start), $record->cancelled_at ? (new \DateTimeImmutable((string) $record->cancelled_at))->format(DATE_ATOM) : null, $record->failure_reason);
    }
}
