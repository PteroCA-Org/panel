<?php

namespace App\Core\Command\Server;

use App\Core\Handler\SyncServersHandler;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:server:sync',
    description: 'Synchronize servers between Pterodactyl and PteroCA (cleanup orphaned servers)',
    aliases: ['pteroca:sync-servers']
)]
class ServerSyncCommand extends Command
{
    public function __construct(
        private readonly SyncServersHandler $syncServersHandler,
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
            'Limit the number of servers to check',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be done without making changes'
        );
        $this->addOption(
            'auto',
            null,
            InputOption::VALUE_NONE,
            'Automatically delete orphaned servers without asking for confirmation (suitable for cron jobs)'
        );
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = $input->getOption('dry-run');
        $auto = $input->getOption('auto');

        if ($dryRun) {
            $io->note('Running in dry-run mode - no changes will be made');
        }

        if ($auto) {
            $io->note('Running in automatic mode - orphaned servers will be deleted automatically');
        }

        $this->syncServersHandler
            ->setLimit($input->getOption('limit') ?: 1000)
            ->setIo($io)
            ->handle($dryRun, $auto);

        return Command::SUCCESS;
    }
}
