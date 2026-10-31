<?php

declare(strict_types=1);

namespace App\Tests\Module\Automation\Application;

use App\Entity\ScheduledTaskType;
use App\Module\Automation\Application\ScheduledTaskConfigurationValidator;
use PHPUnit\Framework\TestCase;

final class ScheduledTaskConfigurationValidatorTest extends TestCase
{
    public function testProviderSearchConfigurationIsNormalizedAndDuplicateTermsAreRemoved(): void
    {
        $validator = new ScheduledTaskConfigurationValidator();

        self::assertSame([
            'provider' => 'youtube',
            'mode' => 'search',
            'query' => 'gaming news',
            'channel_id' => '',
            'category' => 'Games',
            'exclude_terms' => ['spoiler', 'Trailer'],
        ], $validator->normalize(ScheduledTaskType::ProviderImport, [
            'provider' => 'youtube',
            'mode' => 'search',
            'query' => '  gaming news  ',
            'channel_id' => '',
            'category' => ' Games ',
            'exclude_terms' => [' spoiler ', 'Trailer', 'SPOILER'],
        ]));
    }

    public function testProviderSubscriptionRequiresOnlyAValidProviderChannelId(): void
    {
        $validator = new ScheduledTaskConfigurationValidator();
        $channelId = 'UC'.str_repeat('a', 22);

        self::assertSame([
            'provider' => 'youtube',
            'mode' => 'channel',
            'query' => '',
            'channel_id' => $channelId,
            'category' => null,
            'exclude_terms' => [],
        ], $validator->normalize(ScheduledTaskType::ProviderImport, [
            'provider' => 'youtube',
            'mode' => 'channel',
            'query' => '',
            'channel_id' => $channelId,
            'category' => '',
            'exclude_terms' => [],
        ]));
    }

    public function testStoredNullCategoryCanBeRevalidatedWhenTheScheduledImportRuns(): void
    {
        $configuration = [
            'provider' => 'youtube',
            'mode' => 'search',
            'query' => 'gaming',
            'channel_id' => '',
            'category' => null,
            'exclude_terms' => [],
        ];

        self::assertSame($configuration, new ScheduledTaskConfigurationValidator()->normalize(ScheduledTaskType::ProviderImport, $configuration));
    }

    public function testSitemapRefreshAcceptsNoConfiguration(): void
    {
        self::assertSame([], new ScheduledTaskConfigurationValidator()->normalize(ScheduledTaskType::SitemapRefresh, []));
    }

    public function testUnknownConfigurationFieldsAndInvalidModesAreRejected(): void
    {
        $validator = new ScheduledTaskConfigurationValidator();
        $base = [
            'provider' => 'youtube',
            'mode' => 'search',
            'query' => 'gaming',
            'channel_id' => '',
            'category' => '',
            'exclude_terms' => [],
        ];

        try {
            $validator->normalize(ScheduledTaskType::ProviderImport, $base + ['url' => 'https://attacker.example']);
            self::fail('Unknown task configuration must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Die Aufgabe enthält unbekannte Konfigurationsfelder.', $exception->getMessage());
        }

        $base['mode'] = 'url';
        $this->expectException(\InvalidArgumentException::class);
        $validator->normalize(ScheduledTaskType::ProviderImport, $base);
    }

    public function testMalformedAndExcessiveExclusionTermsAreRejected(): void
    {
        $validator = new ScheduledTaskConfigurationValidator();
        $base = [
            'provider' => 'youtube',
            'mode' => 'search',
            'query' => 'gaming',
            'channel_id' => '',
            'category' => '',
            'exclude_terms' => array_fill(0, 21, 'term'),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $validator->normalize(ScheduledTaskType::ProviderImport, $base);
    }

    public function testInvalidUtf8CategoryIsRejected(): void
    {
        $configuration = [
            'provider' => 'youtube',
            'mode' => 'search',
            'query' => 'gaming',
            'channel_id' => '',
            'category' => "\xC3(",
            'exclude_terms' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        new ScheduledTaskConfigurationValidator()->normalize(ScheduledTaskType::ProviderImport, $configuration);
    }
}
