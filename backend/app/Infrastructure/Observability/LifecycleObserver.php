<?php

namespace App\Infrastructure\Observability;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class LifecycleObserver
{
    public function created(Model $record): void
    {
        $this->capture($record, true);
    }

    public function updated(Model $record): void
    {
        if (! $record->wasChanged(['status', 'provider_request_id', 'provider_project_id', 'subtitle_status'])) {
            return;
        }
        $this->capture($record, false);
    }

    private function capture(Model $record, bool $initial): void
    {
        $context = ['operation_type' => Str::snake(class_basename($record)), 'operation_id' => $record->getKey(),
            'status' => $record->status, 'previous_status' => $record->getOriginal('status'),
            'provider' => $record->provider, 'provider_request_id' => $record->provider_request_id ?? $record->provider_project_id,
            'duration_seconds' => $record->duration ?? ($record->duration_ms === null ? null : $record->duration_ms / 1000),
            'media_type' => $record->media_type, 'subtitle_status' => $record->subtitle_status,
            'failure_reason' => $record->failure_reason];
        $createdAt = $record->created_at;
        $source = $record->audio_storage_path ?? $record->source_storage_path;
        $terminal = in_array($record->status, ['complete', 'completed', 'failed'], true);
        DB::afterCommit(function () use ($context, $createdAt, $source, $initial, $terminal): void {
            try {
                $context['source_size_bytes'] = $source === null ? null : DB::table('upload_sessions')->where('storage_path', $source)->value('size');
                if ($context['source_size_bytes'] === null && in_array($context['operation_type'], ['transcription', 'dubbing'], true)) {
                    $quote = DB::table('billing_quotes')->where($context['operation_type'].'_id', $context['operation_id'])->value('source');
                    $metadata = is_string($quote) ? json_decode($quote, true) : [];
                    $context['source_size_bytes'] = $metadata['source_size_bytes'] ?? $metadata['size'] ?? null;
                }
                app(OperationalLogger::class)->record('lifecycle', $context['status'] === 'failed' ? 'error' : 'info',
                    $initial ? 'Operation created.' : 'Operation state changed.', $context,
                    $terminal && $createdAt !== null ? max(0, (int) $createdAt->diffInMilliseconds(now(), false)) : null);
            } catch (Throwable) {
                // Observability must not change the outcome of a committed operation.
            }
        });
    }
}
