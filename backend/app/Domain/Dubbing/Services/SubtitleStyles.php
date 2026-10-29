<?php

namespace App\Domain\Dubbing\Services;

final class SubtitleStyles
{
    public const NAMES = ['classic', 'boxed', 'contrast'];

    public static function catalog(): array
    {
        return [
            ['id' => 'classic', 'name' => 'Classic', 'description' => 'White text with a dark outline'],
            ['id' => 'boxed', 'name' => 'Boxed', 'description' => 'White text on a dark background'],
            ['id' => 'contrast', 'name' => 'High contrast', 'description' => 'Bold yellow text with a dark outline'],
        ];
    }
}
