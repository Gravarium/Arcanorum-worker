<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\VideoCsvImportParser;
use PHPUnit\Framework\TestCase;

final class VideoCsvImportParserTest extends TestCase
{
    public function testParsesBomQuotedCommasAndMultilineDescriptions(): void
    {
        $parser = new VideoCsvImportParser();
        $contents = "\xEF\xBB\xBFtitle,description,source_type,source_url,category,tags\r\n"
            ."\"A, video\",\"First line\nSecond line\",url,https://video.example.test/a.mp4,,gaming;news\r\n";

        $result = $parser->parse($contents);

        self::assertSame([], $result['errors']);
        self::assertSame([[
            'record' => 2,
            'title' => 'A, video',
            'description' => "First line\nSecond line",
            'source_type' => 'url',
            'source_url' => 'https://video.example.test/a.mp4',
            'category' => '',
            'tags' => 'gaming;news',
            'thumbnail_url' => '',
            'duration_seconds' => '',
        ]], $result['rows']);
    }

    public function testParsesTheHeaderlessSixColumnLegacyFormat(): void
    {
        $parser = new VideoCsvImportParser();
        $contents = "https://video.example.test/a.mp4,\"A, video\",Description,\"gaming, news\",https://images.example.test/a.png,120\r\n";

        $result = $parser->parse($contents);

        self::assertSame([], $result['errors']);
        self::assertSame([[
            'record' => 1,
            'title' => 'A, video',
            'description' => 'Description',
            'source_type' => '',
            'source_url' => 'https://video.example.test/a.mp4',
            'category' => '',
            'tags' => 'gaming, news',
            'thumbnail_url' => 'https://images.example.test/a.png',
            'duration_seconds' => '120',
        ]], $result['rows']);
    }

    public function testParsesTheExtendedHeaderFormatAndKeepsOptionalFieldsEmpty(): void
    {
        $parser = new VideoCsvImportParser();
        $contents = "title,description,source_type,source_url,category,tags,thumbnail_url,duration_seconds\n"
            .'Video,Description,url,https://video.example.test/a.mp4,,news,,'."\n";

        $result = $parser->parse($contents);

        self::assertSame([], $result['errors']);
        self::assertSame('', $result['rows'][0]['thumbnail_url']);
        self::assertSame('', $result['rows'][0]['duration_seconds']);
    }

    public function testRejectsUnexpectedHeaders(): void
    {
        $parser = new VideoCsvImportParser();

        $result = $parser->parse("title,description,url_type,source_url,category,tags\n");

        self::assertSame([], $result['rows']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('Kopfzeile', $result['errors'][0]);
    }

    public function testRejectsRowsThatDoNotMatchTheHeaderlessLegacyColumnCount(): void
    {
        $result = new VideoCsvImportParser()->parse("https://video.example.test/a.mp4,Title,Description,Tags,https://images.example.test/a.png\n");

        self::assertSame([], $result['rows']);
        self::assertSame(['Die Datei muss Arcanorums Kopfzeilenformat oder Legacys sechs Spalten ohne Kopfzeile verwenden.'], $result['errors']);
    }

    public function testRejectsInvalidUtf8WithoutReturningUnsafeRows(): void
    {
        $parser = new VideoCsvImportParser();
        $contents = "title,description,source_type,source_url,category,tags\n"
            ."\xFF,Description,url,https://video.example.test/a.mp4,,tag\n";

        $result = $parser->parse($contents);

        self::assertSame([], $result['rows']);
        self::assertSame(['Datensatz 2 enthält ungültige UTF-8-Zeichen.'], $result['errors']);
    }

    public function testMalformedRecordsStillCountTowardRowLimit(): void
    {
        $parser = new VideoCsvImportParser();
        $contents = "title,description,source_type,source_url,category,tags\n"
            .str_repeat("too,few,columns\n", 501);

        $result = $parser->parse($contents);

        self::assertSame([], $result['rows']);
        self::assertContains('Die CSV-Datei darf höchstens 500 Videozeilen enthalten.', $result['errors']);
    }

    public function testRejectsFilesLargerThanTwoMebibytes(): void
    {
        $parser = new VideoCsvImportParser();

        $result = $parser->parse(str_repeat('x', 2_097_153));

        self::assertSame([], $result['rows']);
        self::assertSame(['Die CSV-Datei darf höchstens 2 MiB groß sein.'], $result['errors']);
    }
}
