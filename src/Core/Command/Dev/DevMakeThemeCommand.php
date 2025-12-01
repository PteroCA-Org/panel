<?php

namespace App\Core\Command\Dev;

use App\Core\Handler\MakeThemeHandler;
use App\Core\Service\Template\TemplateManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:dev:make-theme',
    description: 'Make a new theme for PteroCA panel',
    aliases: ['make:theme']
)]
class DevMakeThemeCommand extends Command
{
    public function __construct(
        private readonly TemplateManager $templateManager,
        private readonly MakeThemeHandler $makeThemeHandler
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $io->ask('Theme name', 'new');
        $description = $io->ask('Theme description', '');
        $author = $io->ask('Theme author', '');
        $version = $io->ask('Theme version', '1.0.0');
        $license = $io->ask('Theme license', 'MIT');
        $pterocaVersion = $io->ask('PteroCA version', $this->templateManager->getCurrentTemplateVersion());
        $phpVersion = $io->ask('PHP version', '>=8.2');

        $this->makeThemeHandler->setThemeMetadata([
            'template' => [
                'name' => $name,
                'description' => $description,
                'author' => $author,
                'version' => $version,
                'license' => $license,
                'pterocaVersion' => $pterocaVersion,
                'phpVersion' => $phpVersion,
            ]
        ]);

        $this->makeThemeHandler->handle();
        $io->success('Created a new theme for PteroCA panel');

        return Command::SUCCESS;
    }
}
