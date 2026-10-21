<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InfrastructureCommandTest extends KernelTestCase
{
    public function testConfiguredServicesPassTheCommand(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:infrastructure:check'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        self::assertSame(
            ['database' => true, 'cache' => true, 'ready' => true],
            json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
