<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

final readonly class NativeInteractiveConsoleTerminal implements InteractiveConsoleTerminalInterface
{
    /** @param (\Closure(resource): bool)|null $streamIsTerminalOverride */
    public function __construct(private ?\Closure $streamIsTerminalOverride = null)
    {
    }

    public function isAttachedToTerminal(InputInterface $input, OutputInterface $output): bool
    {
        if (!$input instanceof StreamableInputInterface || !$output instanceof StreamOutput) {
            return false;
        }

        $inputStream = $input->getStream();
        if (null === $inputStream && \defined('STDIN')) {
            $inputStream = \STDIN;
        }

        $outputStream = $output->getStream();

        return is_resource($inputStream)
            && is_resource($outputStream)
            && $this->isTerminalStream($inputStream)
            && $this->isTerminalStream($outputStream);
    }

    /** @param resource $stream */
    private function isTerminalStream($stream): bool
    {
        if (null !== $this->streamIsTerminalOverride) {
            return ($this->streamIsTerminalOverride)($stream);
        }

        return stream_isatty($stream);
    }
}
