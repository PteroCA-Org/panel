<?php

namespace App\Core\Command\Plugin;

use App\Core\Service\Plugin\PluginManager;
use App\Core\Service\Plugin\PluginAssetManager;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:plugin:assets:publish',
    description: 'Publish assets for enabled plugins',
    aliases: ['plugin:assets:publish']
)]
class PluginAssetsPublishCommand extends Command
{
    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly PluginAssetManager $assetManager,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('plugin', 'p', InputOption::VALUE_OPTIONAL, 'Publish assets for specific plugin')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginName = $input->getOption('plugin');

        if ($pluginName !== null) {
            // Publish assets for specific plugin
            return $this->publishSinglePlugin($pluginName, $io);
        }

        // Publish assets for all enabled plugins
        return $this->publishAllPlugins($io);
    }

    private function publishSinglePlugin(string $pluginName, SymfonyStyle $io): int
    {
        $io->info("Publishing assets for plugin: $pluginName");

        $plugin = $this->pluginManager->getPluginByName($pluginName);

        if ($plugin === null) {
            $io->error("Plugin '$pluginName' not found");
            return Command::FAILURE;
        }

        if (!$plugin->isEnabled()) {
            $io->warning("Plugin '$pluginName' is not enabled");
            return Command::FAILURE;
        }

        try {
            $this->assetManager->publishAssets($plugin);
            $io->success("Assets published for plugin: $pluginName");
            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error("Failed to publish assets: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function publishAllPlugins(SymfonyStyle $io): int
    {
        $io->info('Publishing assets for all enabled plugins...');

        $enabledPlugins = $this->pluginManager->getEnabledPlugins();

        if (count($enabledPlugins) === 0) {
            $io->warning('No enabled plugins found');
            return Command::SUCCESS;
        }

        $published = 0;
        $failed = 0;

        foreach ($enabledPlugins as $plugin) {
            try {
                $this->assetManager->publishAssets($plugin);
                ++$published;
                $io->writeln("  <info>✓</info> {$plugin->getDisplayName()}");
            } catch (Exception $e) {
                ++$failed;
                $io->writeln("  <error>✗</error> {$plugin->getDisplayName()}: {$e->getMessage()}");
            }
        }

        $io->newLine();

        if ($failed > 0) {
            $io->warning("Published assets for $published plugin(s), $failed failed");
            return Command::FAILURE;
        }

        $io->success("Published assets for $published plugin(s)");
        return Command::SUCCESS;
    }
}
