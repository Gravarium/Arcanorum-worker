<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LocaleTextDirection;
use PHPUnit\Framework\TestCase;

final class LocaleTextDirectionTest extends TestCase
{
    public function testRecognizedRightToLeftLanguageCodesUseRtlDirection(): void
    {
        foreach (['ar', 'ar-EG', 'fa', 'he', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi'] as $locale) {
            self::assertSame('rtl', LocaleTextDirection::forLocale($locale), $locale);
        }
    }

    public function testLeftToRightAndUnknownLanguageCodesDefaultToLtr(): void
    {
        foreach (['de', 'en', 'fr', 'arx', ''] as $locale) {
            self::assertSame('ltr', LocaleTextDirection::forLocale($locale), $locale);
        }
    }
}
