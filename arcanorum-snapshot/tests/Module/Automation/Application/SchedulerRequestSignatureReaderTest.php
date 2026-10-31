<?php

declare(strict_types=1);

namespace App\Tests\Module\Automation\Application;

use App\Module\Automation\Application\SchedulerRequestSignatureReader;
use PHPUnit\Framework\TestCase;

final class SchedulerRequestSignatureReaderTest extends TestCase
{
    public function testReadsExactLowercaseSignatureFromStandardInput(): void
    {
        $reader = new SchedulerRequestSignatureReader();
        $signature = str_repeat('a1', 32);

        self::assertSame($signature, self::read($reader, $signature));
        self::assertSame($signature, self::read($reader, $signature."\n"));
        self::assertSame($signature, self::read($reader, $signature."\r\n"));
    }

    public function testRejectsMalformedAndOversizedStandardInput(): void
    {
        $reader = new SchedulerRequestSignatureReader();
        foreach ([
            str_repeat('a', 63),
            str_repeat('A', 64),
            str_repeat('a', 64).' ',
            str_repeat('a', 64)."\nextra",
            str_repeat('a', 67),
        ] as $input) {
            self::assertNull(self::read($reader, $input));
        }
    }

    private static function read(SchedulerRequestSignatureReader $reader, string $input): ?string
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $input);
        rewind($stream);
        $signature = $reader->read($stream);
        fclose($stream);

        return $signature;
    }
}
