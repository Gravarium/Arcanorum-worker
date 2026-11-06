<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use PHPUnit\Framework\TestCase;

final class NewsArticleReadingTimeTest extends TestCase
{
    public function testAnEmptyOrMarkupOnlyArticleUsesTheMinimumEstimate(): void
    {
        self::assertSame(1, $this->article('')->getEstimatedReadingMinutes());
        self::assertSame(1, $this->article('<p>&nbsp;</p>')->getEstimatedReadingMinutes());
    }

    public function testReadingTimeRoundsUpAndCountsUnicodeTextInsteadOfMarkup(): void
    {
        self::assertSame(1, $this->article(str_repeat('Wort ', 200))->getEstimatedReadingMinutes());
        self::assertSame(2, $this->article(str_repeat('Wort ', 201))->getEstimatedReadingMinutes());
        self::assertSame(2, $this->article(str_repeat('Wort ', 400))->getEstimatedReadingMinutes());
        self::assertSame(3, $this->article(str_repeat('Wort ', 401))->getEstimatedReadingMinutes());

        $formattedBody = '<p>'.implode(' ', array_fill(0, 200, 'Änderung')).' &amp; &nbsp;Übersicht</p>';
        self::assertSame(2, $this->article($formattedBody)->getEstimatedReadingMinutes());
    }

    private function article(string $body): NewsArticle
    {
        return new NewsArticle(
            'Reading-time test',
            'reading-time-test',
            null,
            null,
            $body,
            PublishingStatus::Published,
            false,
            true,
        );
    }
}
