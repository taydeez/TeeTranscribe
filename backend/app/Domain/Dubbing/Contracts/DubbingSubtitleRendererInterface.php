<?php

namespace App\Domain\Dubbing\Contracts;

use App\Domain\Dubbing\Entities\Dubbing;

interface DubbingSubtitleRendererInterface
{
    /** @return array{subtitle_storage_path: string, captioned_video_storage_path: string} */
    public function render(Dubbing $record, array $subtitles): array;
}
