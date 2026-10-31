<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MemberActivityTranslationTest extends KernelTestCase
{
    public function testMemberActivityModerationMessagesHaveEnglishAndArabicTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);

        $translator->setLocale('en');
        self::assertSame('Member activities', $translator->trans('Mitgliederaktivitäten'));
        self::assertSame('Hide', $translator->trans('Ausblenden'));
        self::assertSame(
            'Member activities updated. 2 entries were changed.',
            $translator->trans('Mitgliederaktivitäten aktualisiert. %count% Einträge wurden geändert.', ['%count%' => 2]),
        );

        $translator->setLocale('ar');
        self::assertSame('أنشطة الأعضاء', $translator->trans('Mitgliederaktivitäten'));
        self::assertSame('إخفاء', $translator->trans('Ausblenden'));
    }
}
