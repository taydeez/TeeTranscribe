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
        if (! $remote && ! empty($result['streams']) && (! is_numeric($seconds) || (float) $seconds <= 0)) {
            $seconds = $this->measurePackets($input);
            $result['format']['duration'] = $seconds;
        }
        if (empty($result['streams']) || ! is_numeric($seconds) || ! is_finite((float) $seconds) || (float) $seconds <= 0 || (float) $seconds > 1_000_000) {
            if ($remote) {
                return null;
            }
            throw new BillingException('This media has no supported audio duration.', 422);
        }

        return $result;
    }

    private function measurePackets(string $input): ?float
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'audio-duration-');
        if ($outputPath === false) {
            throw new BillingException('Media inspection is temporarily unavailable.', 503);
        }
        $stream = null;
        try {
            $process = Process::timeout(120)->run([
                (string) config('billing.ffprobe', 'ffprobe'), '-v', 'error',
                '-protocol_whitelist', 'file,pipe',
                '-format_whitelist', 'mp3,wav,mov,ogg,flac,matroska,webm,aac',
                '-select_streams', 'a:0', '-show_entries', 'packet=pts_time,duration_time',
                '-of', 'compact=p=0', '-o', $outputPath, $input,
            ]);
            if (! $process->successful()) {
                throw new BillingException('The media duration could not be measured.', 422);
            }
            $stream = fopen($outputPath, 'rb');
            if ($stream === false) {
                throw new BillingException('Media inspection is temporarily unavailable.', 503);
            }
            $start = null;
            $end = null;
            while (($line = fgets($stream)) !== false) {
                $fields = [];
                foreach (explode('|', trim($line)) as $field) {
                    $pair = explode('=', $field, 2);
                    if (count($pair) === 2) {
                        $fields[$pair[0]] = $pair[1];
                    }
                }
                $timestamp = $fields['pts_time'] ?? null;
                $duration = $fields['duration_time'] ?? null;
                if (! is_numeric($timestamp) || ! is_numeric($duration) || ! is_finite((float) $timestamp) || ! is_finite((float) $duration) || (float) $duration <= 0) {
                    continue;
                }
                $start = $start === null ? (float) $timestamp : min($start, (float) $timestamp);
                $packetEnd = (float) $timestamp + (float) $duration;
                $end = $end === null ? $packetEnd : max($end, $packetEnd);
            }

            return $start !== null && $end !== null ? $end - $start : null;
        } catch (BillingException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BillingException('Media inspection is temporarily unavailable.', 503);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            unlink($outputPath);
        }
    }
}
