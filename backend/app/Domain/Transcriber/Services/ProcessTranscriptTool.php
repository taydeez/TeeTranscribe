<?php

namespace App\Domain\Transcriber\Services;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Contracts\TranscriptToolGatewayInterface;
use App\Domain\Transcriber\Contracts\TranscriptToolRepositoryInterface;

final readonly class ProcessTranscriptTool
{
    public function __construct(
        private TranscriptToolRepositoryInterface $tools,
        private TranscriptToolGatewayInterface $gateway,
        private BillingRepositoryInterface $billing,
        private CreditService $credits,
        private PrivacyCoordinatorInterface $privacy,
    ) {}

    public function handle(string $id): void
    {
        $record = $this->tools->find($id);
        if ($record === null) {
            $this->credits->releaseTool($id);

            return;
        }
        $this->privacy->exclusive('transcription', $record->transcriptionId, function () use ($id, $record): void {
            if ($this->privacy->projectDeleted('transcription', $record->transcriptionId)) {
                $this->credits->releaseTool($id);

                return;
            }
            $this->process($id);
        });
    }

    private function process(string $id): void
    {
        $this->billing->exclusive('transcript-tool:processing:'.$id, function () use ($id): void {
            $record = $this->tools->find($id);
            if ($record === null) {
                $this->credits->releaseTool($id);

                return;
            }
            if (in_array($record->status, ['complete', 'failed'], true)) {
                return;
            }
            $record = $this->tools->update($id, ['status' => 'processing']);
            if ($record->result === null) {
                $result = $this->gateway->generate($record);
                if ($this->privacy->projectDeleted('transcription', $record->transcriptionId)) {
                    $this->credits->releaseTool($id);

                    return;
                }
                $this->tools->update($id, ['result' => $result]);
            }
            $this->finish($id);
        });
    }

    public function fail(string $id): void
    {
        $this->billing->exclusive('transcript-tool:processing:'.$id, function () use ($id): void {
            $record = $this->tools->find($id);
            if ($record !== null && $record->result !== null && $record->status !== 'failed') {
                $this->finish($id);

                return;
            }
            $this->billing->transaction(function () use ($id): void {
                $record = $this->tools->find($id, lock: true);
                if ($record !== null && $record->status === 'complete') {
                    return;
                }
                if ($record !== null) {
                    $this->tools->update($id, ['status' => 'failed', 'failure_reason' => 'This request failed. Your reserved credits have been returned.']);
                }
                $this->credits->releaseTool($id);
            });
        });
    }

    private function finish(string $id): void
    {
        $this->billing->transaction(function () use ($id): void {
            $record = $this->tools->find($id, lock: true);
            if ($record === null || $record->status === 'failed') {
                $this->credits->releaseTool($id);

                return;
            }
            $this->credits->consumeTool($id);
            $this->tools->update($id, ['status' => 'complete', 'failure_reason' => null]);
        });
    }
}
