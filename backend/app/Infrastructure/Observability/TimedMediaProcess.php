<?php

namespace App\Infrastructure\Observability;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Throwable;

final class TimedMediaProcess
{
    public static function run(array $command, int $timeout, ?string $directory = null): ProcessResult
    {
        $start = hrtime(true);
        $context = ['tool' => str_contains(strtolower(basename($command[0])), 'ffprobe') ? 'ffprobe' : 'ffmpeg'];
        try {
            $process = Process::timeout($timeout);
            if ($directory !== null) {
                $process->path($directory);
            }
            $result = $process->run($command);
            app(OperationalLogger::class)->record('media', $result->successful() ? 'info' : 'error', 'Media process finished.',
                $context + ['exit_code' => $result->exitCode(), 'error' => $result->successful() ? null : $result->errorOutput()], (int) ((hrtime(true) - $start) / 1e6));

            return $result;
        } catch (Throwable $exception) {
            app(OperationalLogger::class)->record('media', 'error', 'Media process failed or timed out.', $context + ['exception' => $exception], (int) ((hrtime(true) - $start) / 1e6));
            throw $exception;
        }
    }
}
