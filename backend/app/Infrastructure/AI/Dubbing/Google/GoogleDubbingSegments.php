<?php

namespace App\Infrastructure\AI\Dubbing\Google;

use RuntimeException;

final class GoogleDubbingSegments
{
    public function parse(array $operation, float $duration, bool $allowEmpty = false): array
    {
        $segments = [];
        foreach ($operation['response']['results'] ?? [] as $file) {
            if (isset($file['error'])) {
                throw new RuntimeException('Google could not transcribe the dubbing source.');
            }
            foreach (($file['inlineResult']['transcript']['results'] ?? $file['transcript']['results'] ?? []) as $result) {
                foreach ($result['alternatives'][0]['words'] ?? [] as $word) {
                    $start = (float) rtrim($word['startOffset'] ?? '0s', 's');
                    $end = min($duration, (float) rtrim($word['endOffset'] ?? '0s', 's'));
                    $text = trim($word['word'] ?? '');
                    if (! is_finite($start) || ! is_finite($end) || $start < 0 || $end <= $start || $end > $duration + 1 || $text === '') {
                        throw new RuntimeException('Google returned invalid dubbing timestamps.');
                    }
                    $last = array_key_last($segments);
                    if ($last !== null && $start < $segments[$last]['end']) {
                        throw new RuntimeException('Google returned overlapping dubbing timestamps.');
                    }
                    $speaker = (string) ($word['speakerLabel'] ?? '0');
                    if ($last !== null && $segments[$last]['speaker'] === $speaker && $end - $segments[$last]['start'] <= 6
                        && strlen($segments[$last]['text'].' '.$text) <= 500 && $start - $segments[$last]['end'] < 0.7
                        && ! preg_match('/[.!?。！？]$/u', $segments[$last]['text'])) {
                        $segments[$last]['end'] = $end;
                        $segments[$last]['text'] .= ' '.$text;
                    } else {
                        $segments[] = ['start' => $start, 'end' => $end, 'text' => $text, 'speaker' => $speaker];
                    }
                }
            }
        }
        if ($segments === [] && ! $allowEmpty) {
            throw new RuntimeException('No timestamped speech was found in the dubbing source.');
        }

        return $segments;
    }
}
