<?php

namespace App\Infrastructure\Observability;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class OperationalLogger
{
    public array $scope = [];

    private bool $writing = false;

    public function __construct(private LogSanitizer $sanitizer) {}

    public function elapsedSinceSubmission(string $id): ?int
    {
        if (! config('observability.enabled')) {
            return null;
        }
        try {
            $start = DB::transaction(fn () => DB::table('operational_logs')->where('operation_type', 'transcription')->where('operation_id', $id)
                ->where('message', 'Transcription submitted to provider.')->orderByDesc('created_at')->value('created_at'));

            return $start === null ? null : max(0, (int) CarbonImmutable::parse($start)->diffInMilliseconds(now(), false));
        } catch (Throwable) {
            return null;
        }
    }

    public function record(string $type, string $level, string $message, array $context = [], ?int $elapsedMs = null): void
    {
        if (! config('observability.enabled') || $this->writing) {
            return;
        }
        $this->writing = true;
        try {
            $context = array_replace($this->scope, $context);
            foreach (['transcription', 'dubbing', 'translation', 'payment', 'quote', 'tool', 'deletion'] as $operation) {
                if (isset($context[$operation.'_id'])) {
                    $context['operation_type'] ??= $operation;
                    $context['operation_id'] ??= $context[$operation.'_id'];
                    break;
                }
            }
            $row = ['id' => (string) Str::ulid(), 'type' => $type, 'level' => $level,
                'message' => $this->sanitizer->text($message), 'elapsed_ms' => $elapsedMs,
                'context' => json_encode($this->sanitizer->context($context), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'created_at' => now()->format('Y-m-d H:i:s.u')];
            foreach (['operation_type' => 40, 'operation_id' => 100, 'provider' => 40, 'provider_request_id' => 255, 'job_id' => 100] as $key => $length) {
                $row[$key] = isset($context[$key]) && is_scalar($context[$key]) ? mb_substr($this->sanitizer->text((string) $context[$key]), 0, $length) : null;
            }
            try {
                Log::channel('operational')->log($level, $row['message'], $row);
            } catch (Throwable) {
                error_log('Operational file log storage unavailable.');
            }
            DB::transaction(fn () => DB::table('operational_logs')->insert($row));
        } catch (Throwable) {
            // Logging must not interrupt customer work, including before migration.
            error_log('Operational log storage unavailable. See the application file logs.');
        } finally {
            $this->writing = false;
        }
    }
}
