<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\InfrastructureProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:infrastructure:check', description: 'Check the configured PostgreSQL 18 and Redis cache services.')]
final class CheckInfrastructureCommand extends Command
{
    public function __construct(private readonly InfrastructureProbe $probe)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!is_string($format) || !in_array($format, ['text', 'json'], true)) {
            $output->writeln('Unsupported output format. Use text or json.');

            return Command::INVALID;
        }

        $database = false;
        $cache = false;
        try {
            $this->probe->checkDatabase();
            $database = true;
        } catch (\Throwable) {
            // Connection exceptions may contain credentials. Never render them.
        }

        try {
            $this->probe->checkCache();
            $cache = true;
        } catch (\Throwable) {
            // Check both dependencies, including when the database has failed.
        }

        if ('json' === $format) {
            $output->writeln(json_encode(['database' => $database, 'cache' => $cache, 'ready' => $database && $cache], JSON_THROW_ON_ERROR));
        } else {
            $output->writeln('PostgreSQL 18: '.($database ? 'OK' : 'FAILED'));
            $output->writeln('Redis cache: '.($cache ? 'OK' : 'FAILED'));
        }

        return $database && $cache ? Command::SUCCESS : Command::FAILURE;
    }
}
