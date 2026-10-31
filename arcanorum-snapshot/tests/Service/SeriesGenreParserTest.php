<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SeriesGenreParser;
use PHPUnit\Framework\TestCase;

final class SeriesGenreParserTest extends TestCase
{
    public function testNamesAreTrimmedAndDuplicateGenresAreCollapsed(): void
    {
        self::assertSame(
            ['Drama', 'science fiction', 'Comedy'],
            SeriesGenreParser::parseNames(" Drama, science   fiction\n drama ; Comedy "),
        );
    }

    public function testGenreCountIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SeriesGenreParser::parseNames('one,two,three,four,five,six,seven,eight,nine,ten,eleven,twelve,thirteen');
    }

    public function testGenreNameLengthIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SeriesGenreParser::parseNames(str_repeat('x', 41));
    }

    public function testInputLengthIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SeriesGenreParser::parseNames(str_repeat('x', 601));
    }

    public function testControlCharactersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SeriesGenreParser::parseNames("Drama\0Comedy");
    }
}
