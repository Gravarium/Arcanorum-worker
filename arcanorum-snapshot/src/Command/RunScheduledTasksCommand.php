<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Automation\Application\ScheduledTaskRunner;
use App\Module\Automation\Application\SchedulerRequestAuthenticator;
use App\Module\Automation\Application\SchedulerRequestSignatureReaderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'arcanorum:scheduled-tasks:run',
    description: 'Führt fällige Automatisierungen mit einer authentifizierten privaten Scheduler-Anfrage aus.',
)]
final class RunScheduledTasksCommand extends Command
{
    public function __construct(
        private readonly SchedulerRequestAuthenticator $authenticator,
        private readonly SchedulerRequestSignatureReaderInterface $signatureReader,
        private readonly ScheduledTaskRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('identity', null, InputOption::VALUE_REQUIRED, 'Feste Kennung des privaten Cron-Hosts')
            ->addOption('issued-at', null, InputOption::VALUE_REQUIRED, 'Unix-Zeitstempel der kurzlebigen Signatur')
            ->addOption('nonce', null, InputOption::VALUE_REQUIRED, 'Einmalige 32-stellige Hexadezimalzahl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identity = $input->getOption('identity');
        $issuedAtValue = $input->getOption('issued-at');
        $nonce = $input->getOption('nonce');
        $signature = $this->signatureReader->read(STDIN);

        if (!is_string($identity)
            || !is_string($issuedAtValue)
            || 1 !== preg_match('/\A[0-9]{10}\z/D', $issuedAtValue)
            || !is_string($nonce)
            || !is_string($signature)
        ) {
            $output->writeln('<error>Die private Scheduler-Anfrage ist unvollständig.</error>');

            return Command::FAILURE;
        }

        try {
            $this->authenticator->authenticate(
                $identity,
                (int) $issuedAtValue,
                $nonce,
                $signature,
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        } catch (\InvalidArgumentException) {
            $output->writeln('<error>Die private Scheduler-Anfrage wurde abgewiesen.</error>');

            return Command::FAILURE;
        }

        $completed = $this->runner->runDueTasks($identity, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $output->writeln(sprintf('%d fällige Aufgaben verarbeitet.', $completed));

        return Command::SUCCESS;
    }
}
