<?php

declare(strict_types=1);

namespace App\Tests\Module\MediaImport;

use App\Module\MediaImport\ImportSource;
use App\Module\MediaImport\MediaProvider;
use App\Module\MediaImport\SourceKind;
use PHPUnit\Framework\TestCase;

final class ImportSourceTest extends TestCase
{
    public function testSupportedCanonicalProviderUrlsBecomeTypedReferences(): void
    {
        self::assertSame(
            'UC_x5XG1OV2P6uZZ5FSM9Ttw',
            ImportSource::fromInput(
                MediaProvider::YouTube,
                SourceKind::Creator,
                'https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw',
            )->reference,
        );
        self::assertSame(
            'PL1234567890',
            ImportSource::fromInput(
                MediaProvider::YouTube,
                SourceKind::Collection,
                'https://youtube.com/playlist?list=PL1234567890',
            )->reference,
        );
        self::assertSame('12345', ImportSource::fromInput(MediaProvider::Vimeo, SourceKind::Creator, 'https://vimeo.com/user12345')->reference);
        self::assertSame('42', ImportSource::fromInput(MediaProvider::Vimeo, SourceKind::Collection, 'https://vimeo.com/showcase/42')->reference);
        self::assertSame('example_user', ImportSource::fromInput(MediaProvider::Dailymotion, SourceKind::Creator, 'https://www.dailymotion.com/user/example_user')->reference);
        self::assertSame('xplaylist1', ImportSource::fromInput(MediaProvider::Dailymotion, SourceKind::Collection, 'https://dailymotion.com/playlist/xplaylist1')->reference);
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testUrlsCannotChangeSchemeHostPortOrPathSemantics(): void
    {
        $invalid = [
            'http://youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw',
            'https://youtube.com.evil.example/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw',
            'https://youtube.com@evil.example/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw',
            'https://youtube.com:443/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw',
            'https://youtube.com/channel/%2e%2e/UC_x5XG1OV2P6uZZ5FSM9Ttw',
            'https://youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw?redirect=https://evil.example',
            'https://youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw#fragment',
            'https://youtube.com/playlist?list=PL1234567890&redirect=https://evil.example',
        ];

        foreach ($invalid as $input) {
            try {
                ImportSource::fromInput(MediaProvider::YouTube, SourceKind::Creator, $input);
                self::fail('A non-canonical or manipulated provider URL was accepted.');
            } catch (\InvalidArgumentException) {
                // Expected validation exception.
            }
        }
    }

    public function testSearchTextIsBoundedAndCannotBeUsedAsAnArbitraryUrl(): void
    {
        self::assertSame(
            'cat videos',
            ImportSource::fromInput(MediaProvider::Vimeo, SourceKind::Search, '  cat videos  ')->reference,
        );

        foreach (['https://attacker.example/', "query\ninjected", str_repeat('x', 161)] as $input) {
            try {
                ImportSource::fromInput(MediaProvider::Vimeo, SourceKind::Search, $input);
                self::fail('Invalid search input was accepted.');
            } catch (\InvalidArgumentException) {
                // Expected validation exception.
            }
        }
    }
}
