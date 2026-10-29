<?php

namespace App\Domain\Transcriber\Services;

use InvalidArgumentException;

final class GoogleTranscriptionCapabilities
{
    private const LOCALES = [
        'af' => 'af-ZA', 'ar' => 'ar-XA', 'hy' => 'hy-AM', 'as' => 'as-IN', 'bn' => 'bn-IN', 'bg' => 'bg-BG',
        'ca' => 'ca-ES', 'zh' => 'cmn-Hans-CN', 'zh-CN' => 'cmn-Hans-CN', 'zh-Hans' => 'cmn-Hans-CN',
        'zh-TW' => 'cmn-Hant-TW', 'zh-Hant' => 'cmn-Hant-TW', 'zh-HK' => 'yue-Hant-HK',
        'hr' => 'hr-HR', 'cs' => 'cs-CZ', 'da' => 'da-DK', 'nl' => 'nl-NL', 'en' => 'en-US', 'et' => 'et-EE',
        'fi' => 'fi-FI', 'fr' => 'fr-FR', 'ka' => 'ka-GE', 'de' => 'de-DE', 'el' => 'el-GR', 'gu' => 'gu-IN',
        'he' => 'iw-IL', 'hi' => 'hi-IN', 'hu' => 'hu-HU', 'id' => 'id-ID', 'it' => 'it-IT', 'ja' => 'ja-JP',
        'kn' => 'kn-IN', 'kk' => 'kk-KZ', 'ko' => 'ko-KR', 'lv' => 'lv-LV', 'lt' => 'lt-LT', 'mk' => 'mk-MK',
        'ms' => 'ms-MY', 'mr' => 'mr-IN', 'mn' => 'mn-MN', 'ne' => 'ne-NP', 'no' => 'no-NO', 'fa' => 'fa-IR',
        'pl' => 'pl-PL', 'pt' => 'pt-BR', 'pa' => 'pa-Guru-IN', 'ro' => 'ro-RO', 'ru' => 'ru-RU', 'sr' => 'sr-RS',
        'sk' => 'sk-SK', 'sl' => 'sl-SI', 'es' => 'es-ES', 'sv' => 'sv-SE', 'tl' => 'fil-PH', 'ta' => 'ta-IN',
        'te' => 'te-IN', 'th' => 'th-TH', 'tr' => 'tr-TR', 'uk' => 'uk-UA', 'vi' => 'vi-VN', 'ha' => 'ha-NG', 'yo' => 'yo-NG',
    ];

    public static function locale(string $language): string
    {
        if (in_array($language, self::LOCALES, true)) {
            return $language;
        }
        if (isset(self::LOCALES[$language])) {
            return self::LOCALES[$language];
        }
        $base = explode('-', $language)[0];
        if (in_array($base, ['ig', 'pcm', 'be', 'bs', 'ps', 'ur'], true)) {
            throw new InvalidArgumentException('The selected speech model does not support this language.');
        }
        $variants = ['en-AU', 'en-IN', 'en-GB', 'en-PH', 'fr-CA', 'pt-PT', 'es-US', 'es-MX'];
        if (in_array($language, $variants, true) || ($base === 'ar' && $language !== 'ar')) {
            return $language;
        }

        return self::LOCALES[$base] ?? throw new InvalidArgumentException('The selected speech model does not support this language.');
    }

    public static function speakers(string $language): bool
    {
        return in_array(self::locale($language), ['cmn-Hans-CN', 'de-DE', 'en-GB', 'en-IN', 'en-US', 'es-ES', 'es-US', 'fr-CA', 'fr-FR', 'hi-IN', 'it-IT', 'ja-JP', 'ko-KR', 'pt-BR'], true);
    }
}
