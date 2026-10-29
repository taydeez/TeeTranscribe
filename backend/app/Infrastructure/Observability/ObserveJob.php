<?php

namespace App\Infrastructure\Observability;

use Closure;
use Throwable;

final class ObserveJob
{
    public function handle(object $job, Closure $next): mixed
    {
        $logger = app(OperationalLogger::class);
        $previous = $logger->scope;
        $context = ['job_name' => $job::class, 'job_id' => $job->job?->uuid(),
            'attempt' => $job->job?->attempts(), 'queue' => $job->job?->getQueue()];
        foreach (['transcription', 'dubbing', 'translation', 'payment', 'quote', 'tool', 'deletion', 'outboxEvent'] as $key) {
            if (isset($job->{$key.'Id'})) {
                $context[$key === 'outboxEvent' ? 'outbox_event_id' : $key.'_id'] = $job->{$key.'Id'};
            }
        }
        $logger->scope = array_replace($previous, $context);
        $start = hrtime(true);
        $logger->record('job', 'info', 'Job attempt started.');
        try {
            $result = $next($job);
            $logger->record('job', $job->job?->hasFailed() ? 'error' : 'info', $job->job?->hasFailed() ? 'Job marked as failed.' : ($job->job?->isReleased() ? 'Job released for another attempt.' : 'Job handler finished; see lifecycle logs for business outcome.'), [], (int) ((hrtime(true) - $start) / 1e6));

            return $result;
        } catch (Throwable $exception) {
            $logger->record('job', 'error', 'Job attempt threw an exception.', ['exception' => $exception], (int) ((hrtime(true) - $start) / 1e6));
            throw $exception;
        } finally {
            $logger->scope = $previous;
        }
    }
}
