<?php

namespace App\Infrastructure\AI\Translation;

final class GoogleTranslationLanguages
{
    /** @return list<array{code: string, name: string}> */
    public static function all(): array
    {
        $names = [
            'yo' => 'Yoruba', 'ig' => 'Igbo', 'ha' => 'Hausa', 'en' => 'English',
            'af' => 'Afrikaans', 'sq' => 'Albanian', 'am' => 'Amharic', 'ar' => 'Arabic',
            'hy' => 'Armenian', 'as' => 'Assamese', 'az' => 'Azerbaijani', 'eu' => 'Basque',
            'be' => 'Belarusian', 'bn' => 'Bengali', 'bs' => 'Bosnian', 'bg' => 'Bulgarian',
            'my' => 'Burmese', 'ca' => 'Catalan', 'ceb' => 'Cebuano',
            'zh-CN' => 'Chinese (Simplified)', 'zh-TW' => 'Chinese (Traditional)',
            'hr' => 'Croatian', 'cs' => 'Czech', 'da' => 'Danish', 'nl' => 'Dutch',
            'et' => 'Estonian', 'fi' => 'Finnish', 'fr' => 'French', 'gl' => 'Galician',
            'ka' => 'Georgian', 'de' => 'German', 'el' => 'Greek', 'gu' => 'Gujarati',
            'ht' => 'Haitian Creole', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hu' => 'Hungarian',
            'is' => 'Icelandic', 'id' => 'Indonesian', 'ga' => 'Irish', 'it' => 'Italian',
            'ja' => 'Japanese', 'jv' => 'Javanese', 'kn' => 'Kannada', 'kk' => 'Kazakh',
            'km' => 'Khmer', 'ko' => 'Korean', 'lo' => 'Lao', 'lv' => 'Latvian',
            'lt' => 'Lithuanian', 'mk' => 'Macedonian', 'ms' => 'Malay', 'ml' => 'Malayalam',
            'mt' => 'Maltese', 'mr' => 'Marathi', 'mn' => 'Mongolian', 'ne' => 'Nepali',
            'no' => 'Norwegian', 'ps' => 'Pashto', 'fa' => 'Persian', 'pl' => 'Polish',
            'pt' => 'Portuguese', 'pa' => 'Punjabi', 'ro' => 'Romanian', 'ru' => 'Russian',
            'sr' => 'Serbian', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'so' => 'Somali',
            'es' => 'Spanish', 'sw' => 'Swahili', 'sv' => 'Swedish', 'tl' => 'Tagalog',
            'ta' => 'Tamil', 'te' => 'Telugu', 'th' => 'Thai', 'tr' => 'Turkish',
            'uk' => 'Ukrainian', 'ur' => 'Urdu', 'uz' => 'Uzbek', 'vi' => 'Vietnamese',
            'cy' => 'Welsh', 'xh' => 'Xhosa', 'zu' => 'Zulu',
        ];

        return array_map(fn (string $code, string $name): array => ['code' => $code, 'name' => $name], array_keys($names), array_values($names));
    }
}
