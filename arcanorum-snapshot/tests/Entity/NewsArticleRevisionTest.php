<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AdminUser;
use App\Entity\NewsArticle;
use App\Entity\NewsArticleRevision;
use App\Entity\PublishingStatus;
use PHPUnit\Framework\TestCase;

final class NewsArticleRevisionTest extends TestCase
{
    public function testCustomNewsFieldsAreCapturedAndParticipateInRevisionIdentity(): void
    {
        $article = new NewsArticle(
            'Custom-field article',
            'custom-field-article',
            null,
            null,
            'Body',
            PublishingStatus::Draft,
            false,
            false,
        );
        $article->replaceCustomFields([['key' => 'Studio', 'value' => 'North']]);
        $revision = new NewsArticleRevision($article, 1, new AdminUser('editor@example.test'));

        self::assertSame([['key' => 'Studio', 'value' => 'North']], $revision->getCustomFields());
        self::assertTrue($revision->matches($article));

        $article->replaceCustomFields([['key' => 'Studio', 'value' => 'South']]);

        self::assertFalse($revision->matches($article));
    }
}
