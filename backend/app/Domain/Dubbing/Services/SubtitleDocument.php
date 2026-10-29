<?php

namespace App\Domain\Dubbing\Services;

use RuntimeException;

final class SubtitleDocument
{
    /** @return array<int, array{start: float, end: float, text: string}> */
    public function cues(array $segments, float $duration): array
    {
        if ($segments === [] || count($segments) > 20000) {
            throw new RuntimeException('The subtitle transcript is unavailable or too large.');
        }
        $cues = [];
        foreach ($segments as $segment) {
            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;
            $text = $segment['text'] ?? null;
            if (! is_numeric($start) || ! is_numeric($end) || ! is_finite((float) $start) || ! is_finite((float) $end)
                || (float) $start < 0 || (float) $end <= (float) $start || ! is_string($text) || ! preg_match('//u', $text)) {
                throw new RuntimeException('The subtitle transcript has invalid timings or text.');
            }
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
            if ($text === '' || (float) $start >= $duration) {
                continue;
            }
            $chunks = $this->chunks($text);
            $start = (float) $start;
            $end = min((float) $end, $duration);
            $length = array_sum(array_map('mb_strlen', $chunks));
            $offset = 0;
            foreach ($chunks as $chunk) {
                $next = $offset + mb_strlen($chunk);
                $cues[] = ['start' => $start + ($end - $start) * $offset / $length,
                    'end' => $start + ($end - $start) * $next / $length, 'text' => $chunk];
                $offset = $next;
            }
        }
        if ($cues === [] || count($cues) > 20000) {
            throw new RuntimeException('No usable subtitles were returned for this video.');
        }
        usort($cues, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $cues;
    }

    public function parseSrt(string $srt): array
    {
        $blocks = preg_split('/\n\s*\n/', trim(str_replace(["\xEF\xBB\xBF", "\r"], '', $srt)));
        $segments = [];
        foreach ($blocks as $block) {
            if (! preg_match('/^(?:\d+\n)?(\d{1,3}):(\d{2}):(\d{2})[,.](\d{3})\s*-->\s*(\d{1,3}):(\d{2}):(\d{2})[,.](\d{3})[^\n]*\n(.+)$/s', trim($block), $match)) {
                throw new RuntimeException('The provider subtitle file could not be read.');
            }
            $segments[] = ['start' => (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3] + (int) $match[4] / 1000,
                'end' => (int) $match[5] * 3600 + (int) $match[6] * 60 + (int) $match[7] + (int) $match[8] / 1000, 'text' => $match[9]];
        }

        return $segments;
    }

    public function srt(array $cues): string
    {
        $result = '';
        foreach ($cues as $index => $cue) {
            $result .= ($index + 1)."\n".$this->timestamp($cue['start'], false).' --> '.$this->timestamp($cue['end'], false)."\n".$cue['text']."\n\n";
        }

        return $result;
    }

    public function ass(array $cues, string $preset, int $width, int $height, string $font): string
    {
        if (! in_array($preset, SubtitleStyles::NAMES, true)) {
            throw new RuntimeException('The subtitle style is unavailable.');
        }
        $font = preg_replace('/[^\pL\pN ._-]/u', '', $font) ?: 'Noto Sans';
        $size = max(20, (int) round(min($width, $height) * 0.045));
        $margin = (int) round($height * 0.05);
        $horizontalMargin = (int) round($width * 0.06);
        $colour = $preset === 'contrast' ? '&H0000FFFF' : '&H00FFFFFF';
        $outline = $preset === 'boxed' ? '&H70000000' : '&H00000000';
        $border = $preset === 'boxed' ? 3 : 1;
        $bold = $preset === 'contrast' ? -1 : 0;
        $result = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$width}\nPlayResY: {$height}\nWrapStyle: 0\nScaledBorderAndShadow: yes\n\n[V4+ Styles]\n"
            ."Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
            ."Style: Default,{$font},{$size},{$colour},{$colour},{$outline},&H80000000,{$bold},0,0,0,100,100,0,0,{$border},2,0,2,{$horizontalMargin},{$horizontalMargin},{$margin},1\n\n[Events]\n"
            ."Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        foreach ($cues as $cue) {
            $text = str_replace(['\\', '{', '}', "\r", "\n"], ['＼', '｛', '｝', '', '\\N'], $cue['text']);
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
            $result .= 'Dialogue: 0,'.$this->timestamp($cue['start'], true).','.$this->timestamp($cue['end'], true).',Default,,0,0,0,,'.$text."\n";
        }

        return $result;
    }

    private function timestamp(float $seconds, bool $ass): string
    {
        $scale = $ass ? 100 : 1000;
        $ticks = (int) round($seconds * $scale);
        $total = intdiv($ticks, $scale);

        return sprintf($ass ? '%d:%02d:%02d.%02d' : '%02d:%02d:%02d,%03d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60, $ticks % $scale);
    }

    private function chunks(string $text): array
    {
        if (strlen($text) > 20000) {
            throw new RuntimeException('A subtitle segment is too large.');
        }
        $chunks = [];
        while (mb_strlen($text) > 84) {
            $head = mb_substr($text, 0, 84);
            $space = mb_strrpos($head, ' ');
            $cut = $space !== false && $space > 40 ? $space : 84;
            $chunks[] = trim(mb_substr($text, 0, $cut));
            $text = trim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }
}
