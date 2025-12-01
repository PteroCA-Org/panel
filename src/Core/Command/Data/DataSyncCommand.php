<?php

namespace App\Core\Command\Data;

use App\Core\Handler\SynchronizeDataHandler;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:data:sync',
    description: 'Synchronize database data with pterodactyl data',
    aliases: ['app:synchronize-data']
)]
class DataSyncCommand extends Command
{
    public function __construct(
        private readonly SynchronizeDataHandler $synchronizeDataHandler,
    )
    {
        parent::__construct();
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->synchronizeDataHandler->handle();

        $io->success('Data synchronized successfully.');
        return Command::SUCCESS;
    }
}
