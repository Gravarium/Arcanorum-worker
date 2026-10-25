<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Domain;

use App\Module\Scheduling\Domain\ScheduleFrequency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleFrequencyTest extends TestCase
{
    public function testValidatesIntervalAndIanaTimezone(): void
    {
        $frequency = ScheduleFrequency::fromInput(3600, 'America/New_York');

        self::assertSame(3600, $frequency->intervalSeconds);
        self::assertSame('America/New_York', $frequency->timezone);
        self::assertSame('America/New_York', $frequency->localize(new \DateTimeImmutable('2026-10-25T12:00:00+00:00'))?->getTimezone()->getName());
    }

    #[DataProvider('invalidIntervals')]
    public function testRejectsIntervalsOutsideTheAllowedWindow(int $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScheduleFrequency::fromInput($seconds, 'UTC');
    }

    /** @return iterable<string, array{int}> */
    public static function invalidIntervals(): iterable
    {
        yield 'too short' => [59];
        yield 'too long' => [2_592_001];
    }

    #[DataProvider('invalidTimezones')]
    public function testRejectsUnknownOrNonIanaTimezones(string $timezone): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ScheduleFrequency::fromInput(3600, $timezone);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTimezones(): iterable
    {
        yield 'unknown name' => ['Mars/Olympus'];
        yield 'offset abbreviation' => ['EST'];
        yield 'fixed offset' => ['+02:00'];
    }

    public function testElapsedIntervalAdvancesInUtcAcrossTimezoneBoundaries(): void
    {
        $frequency = ScheduleFrequency::fromInput(86400, 'Pacific/Auckland');
        $instant = new \DateTimeImmutable('2026-03-28T10:00:00+00:00');

        self::assertSame('2026-03-29T10:00:00+00:00', $frequency->nextAfter($instant)->format(DATE_ATOM));
    }
}
