<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\NativeInteractiveConsoleTerminal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\StreamOutput;

final class NativeInteractiveConsoleTerminalTest extends TestCase
{
    public function testUsesStdinForDefaultArgvInputWithoutAssignedStream(): void
    {
        if (!\defined('STDIN')) {
            self::markTestSkipped('STDIN ist in dieser PHP-SAPI nicht verfügbar.');
        }

        $outputStream = fopen('php://memory', 'w');
        self::assertIsResource($outputStream);

        /** @var list<resource> $checkedStreams */
        $checkedStreams = [];
        $streamIsTerminal = static function (mixed $stream) use (&$checkedStreams): bool {
            if (!is_resource($stream)) {
                return false;
            }

            $checkedStreams[] = $stream;

            return true;
        };

        try {
            $input = new ArgvInput();

            $terminal = new NativeInteractiveConsoleTerminal($streamIsTerminal);

            self::assertTrue($terminal->isAttachedToTerminal($input, new StreamOutput($outputStream)));
            self::assertSame([STDIN, $outputStream], $checkedStreams);
        } finally {
            fclose($outputStream);
        }
    }

    public function testRejectsInputAndOutputStreamsThatAreNotTerminals(): void
    {
        $inputStream = fopen('php://memory', 'r');
        $outputStream = fopen('php://memory', 'w+');
        self::assertIsResource($inputStream);
        self::assertIsResource($outputStream);

        try {
            $input = new StringInput('');
            $input->setStream($inputStream);
            $output = new StreamOutput($outputStream);

            $terminal = new NativeInteractiveConsoleTerminal();
            self::assertFalse($terminal->isAttachedToTerminal($input, $output));
        } finally {
            fclose($inputStream);
            fclose($outputStream);
        }
    }

    public function testRejectsBufferedOutputEvenWhenInputIsStreamable(): void
    {
        $inputStream = fopen('php://memory', 'r');
        self::assertIsResource($inputStream);

        try {
            $input = new StringInput('');
            $input->setStream($inputStream);

            $terminal = new NativeInteractiveConsoleTerminal();
            self::assertFalse($terminal->isAttachedToTerminal($input, new BufferedOutput()));
        } finally {
            fclose($inputStream);
        }
    }
}
