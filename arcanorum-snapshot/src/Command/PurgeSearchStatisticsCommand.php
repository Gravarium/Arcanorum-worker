<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SearchTermStatisticsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:search-statistics:purge', description: 'Entfernt Suchstatistik außerhalb der 30-Tage-Aufbewahrung.')]
final class PurgeSearchStatisticsCommand extends Command
{
    public function __construct(private readonly SearchTermStatisticsRepository $statistics)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->statistics->purgeExpired(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $output->writeln('Abgelaufene Suchstatistik entfernt.');

        return Command::SUCCESS;
    }
}
