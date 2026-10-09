<?php

namespace App\Providers;

use App\Domain\Admin\TwoFactor\Contracts\AdminCodeRepositoryInterface;
use App\Domain\Auth\Contracts\AccountSecurityGatewayInterface;
use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Domain\Auth\Contracts\SocialAuthGatewayInterface;
use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Contracts\MediaDurationInspectorInterface;
use App\Domain\Dubbing\Contracts\AudioDubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Dubbing\Contracts\DubbingSubtitleRendererInterface;
use App\Domain\Dubbing\Contracts\VideoSubtitleTranscriberInterface;
use App\Domain\Folder\Contracts\FolderRepositoryInterface;
use App\Domain\Payment\Contracts\PaymentGatewayResolverInterface;
use App\Domain\Payment\Contracts\PaymentInvoiceMailerInterface;
use App\Domain\Payment\Contracts\PaymentInvoiceStorageInterface;
use App\Domain\Payment\Contracts\PaymentMethodRepositoryInterface;
use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Contracts\PaymentSettingsInterface;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Privacy\Contracts\PrivacyRepositoryInterface;
use App\Domain\Privacy\Contracts\PrivacyStorageInterface;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Contracts\TranscriptionExportDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionExportRepositoryInterface;
use App\Domain\Transcriber\Contracts\TranscriptionExportUrlGeneratorInterface;
use App\Domain\Transcriber\Contracts\TranscriptionMapperInterface as DomainTranscriptionMapperInterface;
use App\Domain\Transcriber\Contracts\TranscriptionOutcomePublisherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionPollingDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Contracts\TranscriptionSubmissionDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptToolGatewayInterface;
use App\Domain\Transcriber\Contracts\TranscriptToolRepositoryInterface;
use App\Domain\Transcriber\Events\TranscriptionCompleted;
use App\Domain\Transcriber\Events\TranscriptionFailed;
use App\Domain\Transcriber\Mappers\TranscriptionMapper as DomainTranscriptionMapper;
use App\Domain\Translation\Contracts\TranslationExportStorageInterface;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;
use App\Domain\Translation\Contracts\TranslationGatewayResolverInterface;
use App\Domain\Translation\Contracts\TranslationRepositoryInterface;
use App\Domain\Upload\Contracts\MultipartStorageInterface;
use App\Domain\Upload\Contracts\UploadSessionRepositoryInterface;
use App\Infrastructure\AI\Dubbing\DeepgramSubtitleTranscriber;
use App\Infrastructure\AI\Dubbing\DubbingGatewayResolver;
use App\Infrastructure\AI\Dubbing\R2DubbedAudioStorage;
use App\Infrastructure\AI\Dubbing\R2DubbingMedia;
use App\Infrastructure\AI\Dubbing\R2DubbingSubtitleRenderer;
use App\Infrastructure\AI\Transcriber\OpenAI\TranscriptToolGateway;
use App\Infrastructure\AI\TranscriberGatewayResolver;
use App\Infrastructure\AI\Translation\Gateways\GoogleTranslationGateway;
use App\Infrastructure\AI\Translation\R2TranslationExportStorage;
use App\Infrastructure\AI\Translation\TranslationGatewayResolver;
use App\Infrastructure\Auth\GoogleSocialAuthGateway;
use App\Infrastructure\Auth\LaravelAccountSecurityGateway;
use App\Infrastructure\Billing\ConfigBillingSettings;
use App\Infrastructure\Billing\FfprobeMediaDurationInspector;
use App\Infrastructure\Notifications\SendTranscriptionOutcomeEmail;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Payment\ConfigPaymentSettings;
use App\Infrastructure\Payment\Invoices\PaymentInvoiceMailer;
use App\Infrastructure\Payment\Invoices\R2PaymentInvoiceStorage;
use App\Infrastructure\Payment\PaymentGatewayResolver;
use App\Infrastructure\Persistence\Eloquent\Contracts\TranscriptionMapperInterface as EloquentTranscriptionMapperInterface;
use App\Infrastructure\Persistence\Eloquent\Mappers\TranscriptionMapper as EloquentTranscriptionMapper;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentAdminCodeRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentBillingRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentDubbingRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentFolderRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentPaymentMethodRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentPaymentRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentPrivacyRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentTranscriptionExportRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentTranscriptionRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentTranscriptToolRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentTranslationRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentUploadSessionRepository;
use App\Infrastructure\Privacy\LaravelPrivacyCoordinator;
use App\Infrastructure\Privacy\R2PrivacyStorage;
use App\Infrastructure\Queue\LaravelTranscriptionExportDispatcher;
use App\Infrastructure\Queue\LaravelTranscriptionPollingDispatcher;
use App\Infrastructure\Queue\LaravelTranscriptionSubmissionDispatcher;
use App\Infrastructure\Storage\R2TranscriptionExportUrlGenerator;
use App\Infrastructure\Upload\R2\R2MultipartStorage;
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
        $this->app->bind(PrivacyRepositoryInterface::class, EloquentPrivacyRepository::class);
        $this->app->bind(PrivacyStorageInterface::class, R2PrivacyStorage::class);
        $this->app->singleton(PrivacyCoordinatorInterface::class, LaravelPrivacyCoordinator::class);
        $this->app->bind(AccountSecurityGatewayInterface::class, LaravelAccountSecurityGateway::class);
        $this->app->bind(DubbingRepositoryInterface::class, EloquentDubbingRepository::class);
        $this->app->bind(DubbingGatewayResolverInterface::class, DubbingGatewayResolver::class);
        $this->app->bind(DubbingGatewayInterface::class, function ($app) {
            $providers = $app->make(DubbingGatewayResolverInterface::class);

            return $providers->resolve($providers->definition()['provider']);
        });
        $this->app->bind(DubbingMediaInterface::class, R2DubbingMedia::class);
        $this->app->bind(AudioDubbingMediaInterface::class, R2DubbedAudioStorage::class);
        $this->app->bind(DubbingSubtitleRendererInterface::class, R2DubbingSubtitleRenderer::class);
        $this->app->bind(VideoSubtitleTranscriberInterface::class, DeepgramSubtitleTranscriber::class);
        $this->app->bind(TranslationRepositoryInterface::class, EloquentTranslationRepository::class);
        $this->app->bind(TranslationGatewayInterface::class, GoogleTranslationGateway::class);
        $this->app->bind(TranslationGatewayResolverInterface::class, TranslationGatewayResolver::class);
        $this->app->bind(TranscriptToolRepositoryInterface::class, EloquentTranscriptToolRepository::class);
        $this->app->bind(TranscriptToolGatewayInterface::class, TranscriptToolGateway::class);
        $this->app->bind(TranslationExportStorageInterface::class, R2TranslationExportStorage::class);
        $this->app->bind(BillingRepositoryInterface::class, EloquentBillingRepository::class);
        $this->app->bind(PaymentRepositoryInterface::class, EloquentPaymentRepository::class);
        $this->app->bind(PaymentMethodRepositoryInterface::class, EloquentPaymentMethodRepository::class);
        $this->app->bind(PaymentSettingsInterface::class, ConfigPaymentSettings::class);
        $this->app->bind(BillingSettingsInterface::class, ConfigBillingSettings::class);
        $this->app->bind(PaymentGatewayResolverInterface::class, PaymentGatewayResolver::class);
        $this->app->bind(PaymentInvoiceStorageInterface::class, R2PaymentInvoiceStorage::class);
        $this->app->bind(PaymentInvoiceMailerInterface::class, PaymentInvoiceMailer::class);
        $this->app->bind(MediaDurationInspectorInterface::class, FfprobeMediaDurationInspector::class);
        $this->app->bind(UploadSessionRepositoryInterface::class, EloquentUploadSessionRepository::class);
        $this->app->bind(MultipartStorageInterface::class, R2MultipartStorage::class);
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
        RateLimiter::for('password-reset', fn ($request) => [
            Limit::perMinute(5)->by('password-reset:ip:'.$request->ip()),
            Limit::perMinute(3)->by('password-reset:email:'.mb_strtolower((string) $request->input('email'))),
        ]);
        RateLimiter::for('verification-email', fn ($request) => Limit::perMinute(1)->by('verification:user:'.$request->user()->getAuthIdentifier()));
        RateLimiter::for('account-password', fn ($request) => Limit::perMinute(5)->by('password-change:user:'.$request->user()->getAuthIdentifier()));
        RateLimiter::for('uploads', fn ($request) => Limit::perMinute(120)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('billing', fn ($request) => Limit::perMinute(60)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('billing-quotes', fn ($request) => Limit::perMinute(5)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('transcript-edits', fn ($request) => [
            Limit::perMinute(30)->by('edits:user:'.$request->user()->getAuthIdentifier()),
            Limit::perMinute(10)->by('edits:transcription:'.$request->user()->getAuthIdentifier().':'.$request->route('transcription')),
        ]);
        RateLimiter::for('translation-edits', fn ($request) => [
            Limit::perMinute(30)->by('translation-edits:user:'.$request->user()->getAuthIdentifier()),
            Limit::perMinute(10)->by('translation-edits:'.$request->user()->getAuthIdentifier().':'.$request->route('translation')),
        ]);
    }
}
