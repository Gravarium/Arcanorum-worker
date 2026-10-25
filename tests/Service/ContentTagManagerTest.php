<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ContentTagManager;
use PHPUnit\Framework\TestCase;

final class ContentTagManagerTest extends TestCase
{
    public function testNamesAreTrimmedAndDuplicateNamesAreCollapsed(): void
    {
        self::assertSame(
            ['Cinema', 'Interviews', 'News'],
            ContentTagManager::parseNames(" Cinema, cinema; Interviews\nNews "),
        );
    }

    public function testTagCountIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentTagManager::parseNames('one,two,three,four,five,six,seven,eight,nine,ten,eleven');
    }

    public function testTagNameLengthIsBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentTagManager::parseNames(str_repeat('x', 101));
    }
}
