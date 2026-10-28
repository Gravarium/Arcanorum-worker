<?php

declare(strict_types=1);

namespace App\Tests\Module\MediaImport;

use App\Module\MediaImport\CsvIssueCode;
use App\Module\MediaImport\CsvSourceError;
use App\Module\MediaImport\CsvSourceException;
use App\Module\MediaImport\CsvSourceParser;
use PHPUnit\Framework\TestCase;

final class CsvSourceParserTest extends TestCase
{
    public function testCsvAcceptsSupportedRowsAndReportsBadRowsWithoutEchoingInput(): void
    {
        $csv = "\xEF\xBB\xBFprovider,source_type,source\n"
            ."youtube,search,\"cats, dogs\"\n"
            ."youtube,search,\"cats, dogs\"\n"
            ."vimeo,creator,https://vimeo.com/user12345\n"
            ."dailymotion,search,unsafe input\n"
            ."youtube,creator,https://evil.example/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw\n";
        $result = new CsvSourceParser()->parse($csv);

        self::assertCount(3, $result->sources);
        self::assertCount(2, $result->issues);
        self::assertSame(CsvIssueCode::DuplicateSource, $result->issues[0]->code);
        self::assertSame(CsvIssueCode::InvalidSource, $result->issues[1]->code);
        self::assertSame(3, $result->issues[0]->rowNumber);
        self::assertStringNotContainsString('evil.example', serialize($result->issues));
    }

    public function testUnknownColumnsAndInvalidEncodingFailTheFileClosed(): void
    {
        try {
            new CsvSourceParser()->parse("provider,source_type,source,extra\n");
            self::fail('Unknown columns were accepted.');
        } catch (CsvSourceException $exception) {
            self::assertSame(CsvSourceError::InvalidHeader, $exception->failure);
        }

        try {
            new CsvSourceParser()->parse("\xFF");
            self::fail('Invalid UTF-8 was accepted.');
        } catch (CsvSourceException $exception) {
            self::assertSame(CsvSourceError::InvalidEncoding, $exception->failure);
        }
    }

    public function testOversizedCsvIsRejectedBeforeParsing(): void
    {
        try {
            new CsvSourceParser()->parse(str_repeat('a', 1_048_577));
            self::fail('An oversized CSV was accepted.');
        } catch (CsvSourceException $exception) {
            self::assertSame(CsvSourceError::TooLarge, $exception->failure);
        }
    }

    public function testRowLimitIsReportedAndProcessingStops(): void
    {
        $csv = "provider,source_type,source\n"
            .implode('', array_map(
                static fn (int $number): string => "youtube,search,source{$number}\n",
                range(1, 501),
            ));
        $result = new CsvSourceParser()->parse($csv);

        self::assertCount(500, $result->sources);
        self::assertCount(1, $result->issues);
        self::assertSame(CsvIssueCode::RowLimitReached, $result->issues[0]->code);
    }

    public function testMalformedRowsAreReportedIndividually(): void
    {
        $csv = "provider,source_type,source\n"
            ."youtube,search,\n"
            ."unknown,search,cats\n"
            ."youtube,invalid,cats\n"
            ."youtube,search,cats,unexpected\n";
        $result = new CsvSourceParser()->parse($csv);

        self::assertSame(
            [
                CsvIssueCode::InvalidSource,
                CsvIssueCode::InvalidProvider,
                CsvIssueCode::InvalidSourceType,
                CsvIssueCode::InvalidColumnCount,
            ],
            array_map(static fn ($issue): CsvIssueCode => $issue->code, $result->issues),
        );
        self::assertSame([], $result->sources);
    }
}
