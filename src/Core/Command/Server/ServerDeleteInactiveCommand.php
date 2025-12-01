<?php

namespace App\Core\Command\Server;

use App\Core\Handler\DeleteInactiveServersHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:server:delete-inactive',
    description: 'Delete inactive servers',
    aliases: ['app:delete-inactive-servers']
)]
class ServerDeleteInactiveCommand extends Command
{
    public function __construct(
        private readonly DeleteInactiveServersHandler $deleteInactiveServersHandler
    )
    {
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->deleteInactiveServersHandler->handle();
        $io->success('Delete inactive servers command executed successfully');

        return Command::SUCCESS;
    }
}
