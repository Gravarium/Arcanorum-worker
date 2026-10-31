<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PrivateObjectDeletionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'arcanorum:private-storage:cleanup', description: 'Bereinigt vorgemerkte private Speicherobjekte mit begrenzten Wiederholungen.')]
final class CleanupPrivateObjectsCommand extends Command
{
    public function __construct(private readonly PrivateObjectDeletionService $deletions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Zahl zu bearbeitender Objekte.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawLimit = $input->getOption('limit');
        if (!is_string($rawLimit) || !ctype_digit($rawLimit)) {
            $output->writeln('<error>Die Bereinigungsgröße muss eine Zahl zwischen 1 und 100 sein.</error>');

            return Command::INVALID;
        }

        $limit = (int) $rawLimit;
        if ($limit < 1 || $limit > 100) {
            $output->writeln('<error>Die Bereinigungsgröße muss eine Zahl zwischen 1 und 100 sein.</error>');

            return Command::INVALID;
        }

        $result = $this->deletions->processDue($limit);
        $output->writeln(sprintf(
            'Private Speicherbereinigung: %d gelöscht, %d fehlgeschlagen, %d vorgemerkt.',
            $result['deleted'],
            $result['failed'],
            $result['pending'],
        ));

        return 0 === $result['failed'] ? Command::SUCCESS : Command::FAILURE;
    }
}
