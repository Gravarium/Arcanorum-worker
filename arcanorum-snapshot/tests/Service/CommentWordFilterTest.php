<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CommentWordFilterMatcher;
use App\Service\CommentWordFilterNormalizer;
use PHPUnit\Framework\TestCase;

final class CommentWordFilterTest extends TestCase
{
    public function testTermNormalizationCollapsesWhitespaceAndIgnoresCase(): void
    {
        self::assertSame(
            ['term' => 'Spoiler Alert', 'normalized' => 'spoiler alert'],
            CommentWordFilterNormalizer::parseTerm("  Spoiler\t  Alert  "),
        );
    }

    public function testEmptyTermsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommentWordFilterNormalizer::parseTerm(" \t\n ");
    }

    public function testTermsLongerThanOneHundredCharactersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommentWordFilterNormalizer::parseTerm(str_repeat('x', 101));
    }

    public function testControlCharactersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommentWordFilterNormalizer::parseTerm("spoiler\0alert");
    }

    public function testFilterMatchesCaseInsensitivePhrasesAcrossWhitespace(): void
    {
        $term = CommentWordFilterNormalizer::parseTerm('Spoiler alert');
        $text = CommentWordFilterNormalizer::normalizeText("This contains SPOILER\t\nALERT!");

        self::assertTrue(CommentWordFilterMatcher::matchesNormalizedText($text, $term['normalized']));
    }

    public function testSingleWordDoesNotMatchInsideAnotherWord(): void
    {
        $term = CommentWordFilterNormalizer::parseTerm('ass');
        $text = CommentWordFilterNormalizer::normalizeText('Classmates discuss the assignment.');

        self::assertFalse(CommentWordFilterMatcher::matchesNormalizedText($text, $term['normalized']));
        self::assertTrue(CommentWordFilterMatcher::matchesNormalizedText(
            CommentWordFilterNormalizer::normalizeText('This is an ASS.'),
            $term['normalized'],
        ));
    }

    public function testCensorReplacementMasksRepeatedCaseInsensitivePhrasesAcrossWhitespace(): void
    {
        $term = CommentWordFilterNormalizer::parseTerm('Spoiler alert');

        self::assertSame(
            'This ***! Another ***.',
            CommentWordFilterMatcher::replaceOccurrences("This SPOILER\tALERT! Another spoiler alert.", $term['normalized']),
        );
    }

    public function testCensorReplacementQuotesRegexCharactersAndRespectsUnicodeWordBoundaries(): void
    {
        $term = CommentWordFilterNormalizer::parseTerm('a+b');

        self::assertSame(
            '*** a-b xx a+bcd',
            CommentWordFilterMatcher::replaceOccurrences('a+b a-b xx a+bcd', $term['normalized']),
        );

        $accentedTerm = CommentWordFilterNormalizer::parseTerm('ass');
        self::assertSame(
            'class café ***',
            CommentWordFilterMatcher::replaceOccurrences('class café ASS', $accentedTerm['normalized']),
        );
    }
}
