<?php

namespace App\Domain\Transcriber\Services;

final class TimedTranscriptSegments
{
    /** @param list<array{start: float, end: float, text: string, speaker: string|null}> $words */
    public static function fromWords(array $words): array
    {
        $segments = [];
        foreach ($words as $word) {
            if (trim($word['text']) === '' || $word['start'] < 0 || $word['end'] < $word['start']) {
                continue;
            }
            $index = count($segments) - 1;
            $previous = $segments[$index] ?? null;
            if ($previous !== null && $previous['speaker'] === $word['speaker']
                && $word['start'] - $previous['end'] < 1.5 && $word['end'] - $previous['start'] <= 15) {
                $segments[$index]['text'] .= ' '.$word['text'];
                $segments[$index]['end'] = $word['end'];
            } else {
                $segments[] = [...$word, 'confidence' => null];
            }
        }

        return $segments;
    }
}
