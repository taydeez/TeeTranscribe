<?php

namespace App\Providers;

use App\Domain\Admin\Log\Contracts\OperationalLogRepositoryInterface;
use App\Infrastructure\Observability\LifecycleObserver;
use App\Infrastructure\Observability\OperationalLogger;
use App\Infrastructure\Observability\ProviderHttpObserver;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptTool;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentOperationalLogRepository;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OperationalLogger::class);
        $this->app->scoped(ProviderHttpObserver::class);
        $this->app->bind(OperationalLogRepositoryInterface::class,
            EloquentOperationalLogRepository::class);
    }

    public function boot(): void
    {
        foreach ([Transcription::class, Dubbing::class, Translation::class, TranscriptTool::class] as $model) {
            $model::observe(LifecycleObserver::class);
        }
        Event::listen(RequestSending::class, [ProviderHttpObserver::class, 'sending']);
        Event::listen(ResponseReceived::class, [ProviderHttpObserver::class, 'received']);
        Event::listen(ConnectionFailed::class, [ProviderHttpObserver::class, 'failed']);
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if (! in_array($event->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
                return;
            }
            app(OperationalLogger::class)->record(isset($event->context['exception']) ? 'exception' : 'application', $event->level, (string) $event->message, $event->context);
        });
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            app(OperationalLogger::class)->record('job', 'error', 'Queue job permanently failed.', [
                'job_id' => $event->job->uuid(), 'job_name' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(), 'attempt' => $event->job->attempts(),
                'connection' => $event->connectionName, 'exception' => $event->exception,
            ]);
        });
    }
}
