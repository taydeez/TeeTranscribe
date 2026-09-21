<?php

namespace App\Domain\Transcriber\Services;

final class TranscriptionExportFileName
{
    public static function make(string $transcriptionName, string $format): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '-', trim($transcriptionName));
        $name = trim((string) $name, ". \t\n\r\0\x0B");

        return mb_substr($name !== '' ? $name : 'transcription', 0, 180).'.'.$format;
    }
}
