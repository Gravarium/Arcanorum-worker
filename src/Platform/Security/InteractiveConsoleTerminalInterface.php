<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface InteractiveConsoleTerminalInterface
{
    public function isAttachedToTerminal(InputInterface $input, OutputInterface $output): bool;
}
