<?php

namespace App\Infrastructure\Billing;

use App\Domain\Billing\Contracts\ActivityConcurrencyInterface;
use App\Domain\Billing\Contracts\BillingConfigurationInterface;
use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final readonly class RedisActivityConcurrency implements ActivityConcurrencyInterface
{
    public function __construct(private BillingConfigurationInterface $settings) {}

    public function assertAvailable(int $userId, string $activity, string $subjectId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Concurrency admission requires a transaction and a locked wallet.');
        }
        $group = match ($activity) {
            'cleanup', 'summary', 'transcript_tool' => 'transcript_tools',
            'video_dubbing', 'audio_dubbing', 'subtitles' => 'dubbing',
            default => $activity,
        };
        try {
            Cache::store(config('billing.concurrency_store', 'redis'))->lock('activities:admission:'.$userId, 15)->block(2, function () use ($userId, $group, $subjectId): void {
                $limits = $this->settings->current()['concurrency'];
                $counts = [];
                foreach (['transcription' => 'transcriptions', 'translation' => 'translations', 'dubbing' => 'dubbings', 'transcript_tools' => 'transcript_tools'] as $key => $table) {
                    $counts[$key] = DB::table($table)->where('user_id', $userId)->whereIn('status', ['pending', 'processing'])
                        ->when($key === $group, fn ($query) => $query->where('id', '!=', $subjectId))->count();
                }
                $total = array_sum($counts);
                if ($total >= $limits['overall']) {
                    throw new BillingException("You already have {$total} tasks queued or processing. Wait for one to finish before starting another.", 429);
                }
                if (($counts[$group] ?? 0) >= $limits[$group]) {
                    $label = match ($group) {
                        'transcription' => 'transcription', 'translation' => 'translation', 'dubbing' => 'dubbing or subtitle', default => 'summary or cleanup',
                    };
                    throw new BillingException("You have reached your {$label} limit ({$limits[$group]}). Wait for a task to finish before starting another.", 429);
                }
            });
        } catch (BillingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Activity admission unavailable.', ['user_id' => $userId, 'exception_type' => $exception::class]);
            throw new BillingException('Task capacity could not be checked. Please try again shortly.', 503);
        }
    }
}
