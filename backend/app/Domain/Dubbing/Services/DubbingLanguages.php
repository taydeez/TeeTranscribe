<?php

namespace App\Domain\Dubbing\Services;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;

final class DubbingLanguages
{
    public function __construct(private ?DubbingGatewayResolverInterface $providers = null) {}

    /** ElevenLabs Dubbing v2 catalog, including supported dialects. */
    private const LANGUAGES = [
        'af' => 'Afrikaans',
        'ak' => 'Akan',
        'sq' => 'Albanian',
        'am' => 'Amharic',
        'ar' => 'Arabic',
        'hy' => 'Armenian',
        'as' => 'Assamese',
        'az' => 'Azerbaijani',
        'eu' => 'Basque',
        'be' => 'Belarusian',
        'bs' => 'Bosnian',
        'bg' => 'Bulgarian',
        'my' => 'Burmese',
        'yue' => 'Cantonese',
        'ca' => 'Catalan',
        'ceb' => 'Cebuano',
        'zh' => 'Chinese',
        'hr' => 'Croatian',
        'cs' => 'Czech',
        'da' => 'Danish',
        'dgo' => 'Dogri',
        'nl' => 'Dutch',
        'en' => 'English',
        'et' => 'Estonian',
        'fil' => 'Filipino (Tagalog)',
        'fi' => 'Finnish',
        'fr' => 'French',
        'gl' => 'Galician',
        'ka' => 'Georgian',
        'de' => 'German',
        'el' => 'Greek',
        'gu' => 'Gujarati',
        'ha' => 'Hausa',
        'he' => 'Hebrew',
        'hi' => 'Hindi',
        'hu' => 'Hungarian',
        'is' => 'Icelandic',
        'id' => 'Indonesian',
        'it' => 'Italian',
        'ja' => 'Japanese',
        'jv' => 'Javanese',
        'kn' => 'Kannada',
        'kk' => 'Kazakh',
        'ki' => 'Kikuyu',
        'rw' => 'Kinyarwanda',
        'rn' => 'Kirundi',
        'ko' => 'Korean',
        'ky' => 'Kyrgyz',
        'lv' => 'Latvian',
        'lt' => 'Lithuanian',
        'lg' => 'Luganda',
        'mk' => 'Macedonian',
        'ms' => 'Malay',
        'ml' => 'Malayalam',
        'cmn' => 'Mandarin Chinese',
        'mr' => 'Marathi',
        'mn' => 'Mongolian',
        'ne' => 'Nepali',
        'no' => 'Norwegian',
        'fa' => 'Persian',
        'pl' => 'Polish',
        'pt' => 'Portuguese',
        'pa' => 'Punjabi',
        'ro' => 'Romanian',
        'ru' => 'Russian',
        'nso' => 'Sepedi',
        'st' => 'Sesotho',
        'sd' => 'Sindhi',
        'sk' => 'Slovak',
        'sl' => 'Slovenian',
        'es' => 'Spanish',
        'su' => 'Sundanese',
        'sw' => 'Swahili',
        'ss' => 'Swati',
        'sv' => 'Swedish',
        'tg' => 'Tajik',
        'ta' => 'Tamil',
        'te' => 'Telugu',
        'th' => 'Thai',
        'bo' => 'Tibetan',
        'ts' => 'Tsonga',
        'tn' => 'Tswana',
        'tr' => 'Turkish',
        'uk' => 'Ukrainian',
        'ur' => 'Urdu',
        'ug' => 'Uyghur',
        'uz' => 'Uzbek',
        've' => 'Venda',
        'vi' => 'Vietnamese',
        'war' => 'Waray',
        'cy' => 'Welsh',
        'wo' => 'Wolof',
        'yo' => 'Yoruba',
        'zu' => 'Zulu',
        'ar-EG' => 'Arabic (Egypt)',
        'zh-TW' => 'Chinese (Taiwan)',
        'en-AU' => 'English (Australia)',
        'en-CA' => 'English (Canada)',
        'en-GB' => 'English (United Kingdom)',
        'en-US' => 'English (United States)',
        'fr-CA' => 'French (Canada)',
        'fr-FR' => 'French (France)',
        'pt-BR' => 'Portuguese (Brazil)',
        'pt-PT' => 'Portuguese (Portugal)',
        'es-AR' => 'Spanish (Argentina)',
        'es-CL' => 'Spanish (Chile)',
        'es-ES' => 'Spanish (Spain)',
        'es-MX' => 'Spanish (Mexico)',
    ];

    public function all(string $mediaType = 'video', ?string $language = null): array
    {
        return $this->providers?->languages($mediaType, $language) ?? $this->sourceLanguages();
    }

    public function sourceLanguages(): array
    {
        $items = [];
        foreach (self::LANGUAGES as $code => $name) {
            $items[] = ['code' => $code, 'name' => $name, 'nigerian' => in_array($code, ['yo', 'ha'], true)];
        }
        $priority = ['yo' => 0, 'ha' => 1, 'en' => 2];
        usort($items, fn ($a, $b) => (($priority[$a['code']] ?? 3) <=> ($priority[$b['code']] ?? 3)) ?: strcasecmp($a['name'], $b['name']));

        return $items;
    }

    public static function routingCode(string $language): string
    {
        foreach (self::LANGUAGES as $code => $name) {
            if (strcasecmp($name, $language) === 0) {
                return strtolower($code);
            }
        }

        return strtolower($language);
    }

    public function validate(?string $source, string $target, string $mediaType = 'video'): void
    {
        if (! in_array($target, array_column($this->all($mediaType, $target), 'code'), true) || ($source !== null && ! isset(self::LANGUAGES[$source]))) {
            throw new BillingException('Select a supported dubbing language.', 422);
        }
        if ($source !== null && (explode('-', $source)[0] === explode('-', $target)[0]
            || (self::LANGUAGES[$source] ?? null) === $target)) {
            throw new BillingException('Choose a different output language.', 422);
        }
    }
}
