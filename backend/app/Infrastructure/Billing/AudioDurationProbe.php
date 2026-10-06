<?php

namespace App\Infrastructure\Billing;

use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Support\Facades\Process;
use Throwable;

final class AudioDurationProbe
{
    public function inspect(string $input, bool $remote = false): ?array
    {
        $command = [(string) config('billing.ffprobe', 'ffprobe'), '-v', 'error', '-protocol_whitelist', $remote ? 'https,tls,tcp' : 'file,pipe', '-format_whitelist', 'mp3,wav,mov,ogg,flac,matroska,webm,aac'];
        if ($remote) {
            array_push($command, '-rw_timeout', '5000000', '-probesize', '1048576', '-analyzeduration', '1000000');
        }
        array_push($command, '-select_streams', 'a:0', '-show_entries', 'format=duration,format_name:stream=index', '-of', 'json', $input);
        try {
            $process = Process::timeout($remote ? 20 : 120)->run($command);
        } catch (Throwable) {
            if ($remote) {
                return null;
            }
            throw new BillingException('Media inspection is temporarily unavailable.', 503);
        }
        if (! $process->successful()) {
            if ($remote) {
                return null;
            }
            throw new BillingException('The media duration could not be measured.', 422);
        }
        $result = json_decode($process->output(), true);
        $seconds = $result['format']['duration'] ?? null;
        if (empty($result['streams']) || ! is_numeric($seconds) || ! is_finite((float) $seconds) || (float) $seconds <= 0 || (float) $seconds > 1_000_000) {
            if ($remote) {
                return null;
            }
            throw new BillingException('This media has no supported audio duration.',422);
        }

        return $result;
    }
}
