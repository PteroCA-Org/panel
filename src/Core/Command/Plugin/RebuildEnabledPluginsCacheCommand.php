<?php

namespace App\Core\Command\Plugin;

use App\Core\Service\Plugin\EnabledPluginsCacheManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'plugin:rebuild-cache',
    description: 'Rebuild the enabled plugins cache'
)]
class RebuildEnabledPluginsCacheCommand extends Command
{
    public function __construct(
        private readonly EnabledPluginsCacheManager $cacheManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->info('Rebuilding enabled plugins cache...');

        $result = $this->cacheManager->rebuildCache();

        if ($result) {
            $io->success('Enabled plugins cache rebuilt successfully.');
            return Command::SUCCESS;
        }

        $io->error('Failed to rebuild enabled plugins cache.');
        return Command::FAILURE;
    }
}
