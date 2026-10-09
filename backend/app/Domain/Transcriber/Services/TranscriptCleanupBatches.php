<?php

namespace App\Domain\Transcriber\Services;

use App\Domain\Billing\Exceptions\BillingException;

final class TranscriptCleanupBatches
{
    /** @return list<list<array{segment: int, text: string}>> */
    public static function plan(string $text, array $segments): array
    {
        if (! array_is_list($segments)) {
            throw new BillingException('The saved transcript contains invalid speaker segments.', 422);
        }
        $sources = $segments === [] ? [$text] : [];
        foreach ($segments as $segment) {
            if (! is_array($segment) || ! is_string($segment['text'] ?? null)) {
                throw new BillingException('The saved transcript contains invalid speaker segments.', 422);
            }
            $sources[] = $segment['text'];
        }
        $batches = [];
        $batch = [];
        foreach ($sources as $segment => $source) {
            if (! is_string($source) || ! mb_check_encoding($source, 'UTF-8')) {
                throw new BillingException('The saved transcript contains invalid text.', 422);
            }
            $offset = 0;
            do {
                $chunk = self::chunk(substr($source, $offset));
                $unit = ['segment' => $segment, 'text' => $chunk];
                if ($batch !== [] && (count($batch) >= 250 || self::bytes([...$batch, $unit]) > 8000)) {
                    $batches[] = $batch;
                    $batch = [];
                    if (count($batches) >= 15) {
                        throw new BillingException('This transcript needs too many cleanup requests. Split it into shorter sections.', 422);
                    }
                }
                $batch[] = $unit;
                $offset += strlen($chunk);
            } while ($offset < strlen($source));
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    private static function chunk(string $text): string
    {
        $chunk = mb_strcut($text, 0, 6000, 'UTF-8');
        if (self::bytes([['segment' => 0, 'text' => $chunk]]) > 8000) {
            $lower = 1;
            $upper = strlen($chunk);
            $fitting = '';
            while ($lower <= $upper) {
                $middle = intdiv($lower + $upper, 2);
                $candidate = mb_strcut($chunk, 0, $middle, 'UTF-8');
                if (self::bytes([['segment' => 0, 'text' => $candidate]]) <= 8000) {
                    $fitting = $candidate;
                    $lower = $middle + 1;
                } else {
                    $upper = $middle - 1;
                }
            }
            $chunk = $fitting;
        }
        if (strlen($chunk) < strlen($text)) {
            $newline = strrpos($chunk, "\n");
            if ($newline !== false && $newline >= intdiv(strlen($chunk), 2)) {
                return substr($chunk, 0, $newline + 1);
            }
            if (preg_match('/\s+(?=\S*$)/u', $chunk, $boundary, PREG_OFFSET_CAPTURE)
                && $boundary[0][1] >= intdiv(strlen($chunk), 2)) {
                return substr($chunk, 0, $boundary[0][1] + strlen($boundary[0][0]));
            }
        }

        return $chunk;
    }

    /** @param list<array{segment: int, text: string}> $units */
    private static function bytes(array $units): int
    {
        return strlen(json_encode(['segments' => array_column($units, 'text')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
