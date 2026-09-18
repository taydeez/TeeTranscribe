<?php

namespace App\Providers;

use App\Domain\Transcriber\Contracts\TranscriptionExportRepositoryInterface;
use App\Domain\Transcriber\Contracts\TranscriptionMapperInterface as DomainTranscriptionMapperInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Mappers\TranscriptionMapper as DomainTranscriptionMapper;
use App\Infrastructure\Persistence\Eloquent\Contracts\TranscriptionMapperInterface as EloquentTranscriptionMapperInterface;
use App\Infrastructure\Persistence\Eloquent\Mappers\TranscriptionMapper as EloquentTranscriptionMapper;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentTranscriptionExportRepository;
use App\Infrastructure\Persistence\Eloquent\Repositories\EloquentTranscriptionRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TranscriptionExportRepositoryInterface::class, EloquentTranscriptionExportRepository::class);
        $this->app->bind(TranscriptionRepositoryInterface::class, EloquentTranscriptionRepository::class);
        $this->app->bind(DomainTranscriptionMapperInterface::class, DomainTranscriptionMapper::class);
        $this->app->bind(EloquentTranscriptionMapperInterface::class, EloquentTranscriptionMapper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
