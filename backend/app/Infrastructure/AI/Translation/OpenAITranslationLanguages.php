<?php

namespace App\Infrastructure\AI\Translation;

final class OpenAITranslationLanguages
{
    /** @return list<array{code: string, name: string}> */
    public static function all(): array
    {
        $names = [
            'yo' => 'Yoruba', 'ig' => 'Igbo', 'ha' => 'Hausa', 'en' => 'English',
            'af' => 'Afrikaans', 'ar' => 'Arabic', 'hy' => 'Armenian', 'bn' => 'Bengali',
            'bg' => 'Bulgarian', 'ca' => 'Catalan', 'zh-CN' => 'Chinese (Simplified)', 'zh-TW' => 'Chinese (Traditional)',
            'hr' => 'Croatian', 'cs' => 'Czech', 'da' => 'Danish', 'nl' => 'Dutch', 'et' => 'Estonian',
            'fi' => 'Finnish', 'fr' => 'French', 'ka' => 'Georgian', 'de' => 'German', 'el' => 'Greek',
            'gu' => 'Gujarati', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hu' => 'Hungarian', 'id' => 'Indonesian',
            'it' => 'Italian', 'ja' => 'Japanese', 'kn' => 'Kannada', 'ko' => 'Korean', 'lv' => 'Latvian',
            'lt' => 'Lithuanian', 'ms' => 'Malay', 'mr' => 'Marathi', 'ne' => 'Nepali', 'no' => 'Norwegian',
            'fa' => 'Persian', 'pl' => 'Polish', 'pt' => 'Portuguese', 'pa' => 'Punjabi', 'ro' => 'Romanian',
            'ru' => 'Russian', 'sr' => 'Serbian', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'es' => 'Spanish',
            'sw' => 'Swahili', 'sv' => 'Swedish', 'tl' => 'Tagalog', 'ta' => 'Tamil', 'te' => 'Telugu',
            'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian', 'ur' => 'Urdu', 'vi' => 'Vietnamese',
        ];

        return array_map(fn (string $code, string $name): array => ['code' => $code, 'name' => $name], array_keys($names), array_values($names));
    }
}
