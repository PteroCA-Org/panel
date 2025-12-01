<?php

namespace App\Core\Command\Data;

use App\Core\Handler\MigrateServersHandler;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:data:migrate-servers',
    description: 'Migrate servers from Pterodactyl to existing user accounts in PteroCA',
    aliases: ['pterodactyl:migrate-servers']
)]
class DataMigrateServersCommand extends Command
{
    public function __construct(
        private readonly MigrateServersHandler $migrateServersHandler,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_OPTIONAL,
            'Limit the number of servers to migrate',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be done without making changes'
        );
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Running in dry-run mode - no changes will be made');
        }

        $this->migrateServersHandler
            ->setLimit($input->getOption('limit') ?: 100)
            ->setIo($io)
            ->handle($dryRun);

        return Command::SUCCESS;
    }
}
