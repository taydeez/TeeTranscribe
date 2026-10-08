<?php

namespace App\Domain\Transcriber\Services;

final class TranscriptionExportOptions
{
    public const FORMATS = ['txt', 'pdf', 'docx'];

    public static function hasSpeakers(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (is_string($segment['speaker'] ?? null) && trim($segment['speaker']) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{format: string, variant: string}> */
    public static function required(array $segments): array
    {
        $options = [];
        foreach (self::hasSpeakers($segments) ? ['plain', 'speakers'] : ['plain'] as $variant) {
            foreach (self::FORMATS as $format) {
                $options[] = ['format' => $format, 'variant' => $variant];
            }
        }

        return $options;
    }

    public static function speakerText(array $segments): string
    {
        $paragraphs = [];
        $speaker = null;
        foreach ($segments as $segment) {
            $label = trim($segment['speaker'] ?? '') ?: 'Unknown speaker';
            $text = trim($segment['text'] ?? '');
            if ($text === '') {
                continue;
            }
            if ($speaker === $label) {
                $paragraphs[array_key_last($paragraphs)] .= ' '.$text;
            } else {
                $paragraphs[] = $label.":\n".$text;
                $speaker = $label;
            }
        }

        return implode("\n\n", $paragraphs);
    }
}
