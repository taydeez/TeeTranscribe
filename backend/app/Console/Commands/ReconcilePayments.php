<?php

namespace App\Console\Commands;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Services\PaymentService;
use App\Domain\Transcriber\Services\ProcessTranscriptTool;
use App\Domain\Translation\Contracts\TranslationRepositoryInterface;
use App\Domain\Translation\Services\ProcessTranslation;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePayments extends Command
{
    protected $signature = 'billing:reconcile';

    protected $description = 'Verify pending payments missed by the webhook';

    public function handle(PaymentRepositoryInterface $repository, PaymentService $payments): int
    {
        $repository->expireUnconfirmedPayments();

        foreach ($repository->pendingPayments() as $payment) {
            try {
                $payments->verify($payment['reference']);
            } catch (Throwable $exception) {
                Log::warning('Payment reconciliation deferred.', [
                    'payment_id' => $payment['id'],
                    'gateway' => $payment['gateway'],
                    'exception_type' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        UsageCharge::where('status', 'reserved')
            ->where('created_at', '<=', now()->subHours(config('billing.processing_timeout_hours', 26)))
            ->chunkById(100, function ($charges): void {
                foreach ($charges as $charge) {
                    if ($charge->transcript_tool_id !== null) {
                        app(ProcessTranscriptTool::class)->fail($charge->transcript_tool_id);

                        continue;
                    }
                    if ($charge->dubbing_id !== null) {
                        app(ProcessDubbing::class)->fail($charge->dubbing_id);

                        continue;
                    }
                    if ($charge->translation_id !== null) {
                        $translation = app(TranslationRepositoryInterface::class)->find($charge->translation_id);
                        if ($translation === null) {
                            app(CreditService::class)->releaseTranslation($charge->translation_id);
                        } elseif ($translation->translatedText === null) {
                            app(ProcessTranslation::class)->fail($translation->id, $translation->exportRevision);
                        }

                        continue;
                    }
                    $record = Transcription::find($charge->transcription_id);
                    if ($record === null || $record->status === 'pending') {
                        app(TranscriptionOutcomePublisher::class)->failed($charge->transcription_id, pendingOnly: true);
                    }
                }
            });

        return self::SUCCESS;
    }
}
