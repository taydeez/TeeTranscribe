<?php

namespace App\Providers;

use App\Domain\Admin\TwoFactor\Contracts\AdminCodeRepositoryInterface;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Domain\Folder\Contracts\FolderRepositoryInterface;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Contracts\TranscriptionExportDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionExportRepositoryInterface;
use App\Domain\Transcriber\Contracts\TranscriptionExportUrlGeneratorInterface;
use App\Domain\Transcriber\Contracts\TranscriptionMapperInterface as DomainTranscriptionMapperInterface;
use App\Domain\Transcriber\Contracts\TranscriptionOutcomePublisherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionPollingDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Contracts\TranscriptionSubmissionDispatcherInterface;
use App\Domain\Transcriber\Events\TranscriptionCompleted;
use App\Domain\Transcriber\Events\TranscriptionFailed;
use App\Domain\Transcriber\Mappers\TranscriptionMapper as DomainTranscriptionMapper;
use App\Infrastructure\AI\TranscriberGatewayResolver;
use App\Infrastructure\Auth\GoogleSocialAuthGateway;
use App\Infrastructure\Notifications\SendTranscriptionOutcomeEmail;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Contracts\TranscriptionMapperInterface as EloquentTranscriptionMapperInterface;
use App\Infrastructure\Persistence\Eloquent\Mappers\TranscriptionMapper as EloquentTranscriptionMapper;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentAdminCodeRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentFolderRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentTranscriptionExportRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentTranscriptionRepository;
use App\Infrastructure\Queue\LaravelTranscriptionExportDispatcher;
use App\Infrastructure\Queue\LaravelTranscriptionPollingDispatcher;
use App\Infrastructure\Queue\LaravelTranscriptionSubmissionDispatcher;
use App\Infrastructure\Storage\R2TranscriptionExportUrlGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TranscriptionOutcomePublisherInterface::class, TranscriptionOutcomePublisher::class);
        $this->app->bind(TranscriptionExportRepositoryInterface::class, EloquentTranscriptionExportRepository::class);
        $this->app->bind(TranscriptionExportDispatcherInterface::class, LaravelTranscriptionExportDispatcher::class);
        $this->app->bind(TranscriptionExportUrlGeneratorInterface::class, R2TranscriptionExportUrlGenerator::class);
        $this->app->bind(AuthRepositoryInterface::class, EloquentAuthRepository::class);
        $this->app->bind(SocialAuthGatewayInterface::class, GoogleSocialAuthGateway::class);
        $this->app->bind(AdminCodeRepositoryInterface::class, EloquentAdminCodeRepository::class);
        $this->app->bind(FolderRepositoryInterface::class, EloquentFolderRepository::class);
        $this->app->bind(TranscriptionRepositoryInterface::class, EloquentTranscriptionRepository::class);
        $this->app->bind(TranscriberGatewayResolverInterface::class, TranscriberGatewayResolver::class);
        $this->app->bind(TranscriptionPollingDispatcherInterface::class, LaravelTranscriptionPollingDispatcher::class);
        $this->app->bind(TranscriptionSubmissionDispatcherInterface::class, LaravelTranscriptionSubmissionDispatcher::class);
        $this->app->bind(DomainTranscriptionMapperInterface::class, DomainTranscriptionMapper::class);
        $this->app->bind(EloquentTranscriptionMapperInterface::class, EloquentTranscriptionMapper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            [TranscriptionCompleted::class, TranscriptionFailed::class],
            SendTranscriptionOutcomeEmail::class,
        );
        RateLimiter::for('auth', fn ($request) => Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
