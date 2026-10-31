<?php

declare(strict_types=1);

namespace App\Service;

final class LocaleTextDirection
{
    /** @var list<string> */
    private const array RIGHT_TO_LEFT_LANGUAGES = ['ar', 'fa', 'he', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi'];

    public static function forLocale(string $locale): string
    {
        $language = strtolower(explode('-', $locale, 2)[0]);

        return in_array($language, self::RIGHT_TO_LEFT_LANGUAGES, true) ? 'rtl' : 'ltr';
    }
}
