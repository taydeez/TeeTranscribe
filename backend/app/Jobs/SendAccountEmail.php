<?php

namespace App\Jobs;

use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Models\User;
use App\Notifications\CreditMovementNotification;
use App\Notifications\NewAccountNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class SendAccountEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(public string $outboxEventId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'account-email:'.$this->outboxEventId;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $event = OutboxEvent::query()->lockForUpdate()->find($this->outboxEventId);
            if ($event === null || $event->published_at !== null) {
                return;
            }
            $user = User::query()->find($event->payload['user_id'] ?? null);
            if ($user === null || blank($user->email)) {
                $event->update(['published_at' => now()]);

                return;
            }
            if ($event->event_type === 'AccountRegistered') {
                $notification = new NewAccountNotification;
            } elseif (in_array($event->event_type, ['CreditsReserved', 'CreditsReturned'], true)) {
                $entry = CreditTransaction::query()->where('user_id', $user->id)->findOrFail($event->aggregate_id);
                $activity = isset($entry->metadata['translation_id']) ? 'translation'
                    : (isset($entry->metadata['dubbing_id']) ? 'dubbing' : 'transcription');
                $notification = new CreditMovementNotification($entry->kind, $entry->amount_units,
                    $entry->available_after, $entry->reserved_after, $activity);
            } else {
                throw new \LogicException('Unsupported account email event.');
            }
            $user->notifyNow($notification);
            $event->update(['published_at' => now()]);
        });
    }
}
