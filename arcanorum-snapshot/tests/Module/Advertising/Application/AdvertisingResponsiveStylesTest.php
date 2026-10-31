<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Application;

use PHPUnit\Framework\TestCase;

final class AdvertisingResponsiveStylesTest extends TestCase
{
    public function testBannerAndVideoAdsStayUsableInNarrowAndKeyboardDrivenLayouts(): void
    {
        $stylesheet = file_get_contents(dirname(__DIR__, 4).'/public/styles/app.css');
        self::assertIsString($stylesheet);

        $this->assertRuleContains($stylesheet, '.cms-ad', [
            'max-width' => '100%',
            'min-width' => '0',
            'overflow-wrap' => 'anywhere',
        ]);
        $this->assertRuleContains($stylesheet, '.cms-ad a', [
            'max-width' => '100%',
            'min-width' => '0',
            'min-height' => '2.75rem',
        ]);
        $this->assertRuleContains($stylesheet, '.cms-ad--player > a', [
            'display' => 'flex',
            'flex-wrap' => 'wrap',
        ]);
        $this->assertRuleContains($stylesheet, '.cms-ad--video video', [
            'max-height' => 'min(32rem, 70vh)',
            'object-fit' => 'contain',
        ]);
        $this->assertRuleContains($stylesheet, '.cms-ad a:focus-visible', [
            'outline' => '3px solid var(--site-accent)',
        ]);

        self::assertMatchesRegularExpression(
            '/\.cms-ad img\s*,\s*\.cms-ad video\s*\{[^}]*max-width:\s*100%\s*;/s',
            $stylesheet,
        );
    }

    /** @param array<string, string> $declarations */
    private function assertRuleContains(string $stylesheet, string $selector, array $declarations): void
    {
        $selectorParts = preg_split('/\s+/', trim($selector), -1, PREG_SPLIT_NO_EMPTY);
        if (false === $selectorParts) {
            throw new \LogicException('Could not parse the expected CSS selector.');
        }

        $selectorPattern = implode('\s*', array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            $selectorParts,
        ));
        $status = preg_match('/'.$selectorPattern.'\s*\{([^}]*)\}/s', $stylesheet, $matches);

        self::assertSame(
            1,
            $status,
            sprintf('Expected a CSS rule for "%s".', $selector),
        );
        $rule = $matches[1] ?? '';

        foreach ($declarations as $property => $value) {
            self::assertMatchesRegularExpression(
                '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*(?:;|$)/s',
                trim($rule).';',
                sprintf('Expected %s: %s in the "%s" rule.', $property, $value, $selector),
            );
        }
    }
}
