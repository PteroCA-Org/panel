<?php

namespace App\Core\Command\User;

use App\Core\Enum\UserRoleEnum;
use App\Core\Exception\CouldNotCreatePterodactylClientApiKeyException;
use App\Core\Handler\CreateNewUserHandler;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:user:create',
    description: 'Create a new user',
    aliases: ['app:create-new-user']
)]
class UserCreateCommand extends Command
{
    public function __construct(
        private readonly CreateNewUserHandler $createNewUserHandler,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'User password')
            ->addArgument('role', InputArgument::OPTIONAL, 'User role', UserRoleEnum::ROLE_USER->name)
        ;
    }

    /**
     * @throws CouldNotCreatePterodactylClientApiKeyException
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $password = $input->getArgument('password');
        $role = UserRoleEnum::tryFrom($input->getArgument('role')) ?? UserRoleEnum::ROLE_USER;

        try {
            $this->createNewUserHandler->setUserCredentials($email, $password, $role);
            $this->createNewUserHandler->handle();
        } catch (CouldNotCreatePterodactylClientApiKeyException $exception) {
            $io->warning($exception->getMessage());
            $continueWithoutKey = $io->ask(
                'Do you want to create account without creating a Pterodactyl API key? Not all features will be available. (yes/no)',
                'no'
            );
            if ($continueWithoutKey === 'yes') {
                $this->createNewUserHandler->handle(true);
            } else {
                $io->error('User creation failed. Could not create Pterodactyl Client Account API key.');
                return Command::FAILURE;
            }
        }

        $io->success('New user created!');

        return Command::SUCCESS;
    }
}
