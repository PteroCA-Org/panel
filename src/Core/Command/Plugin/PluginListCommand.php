<?php

namespace App\Core\Command\Plugin;

use App\Core\Service\Plugin\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:plugin:list',
    description: 'List all registered plugins',
    aliases: ['plugin:list']
)]
class PluginListCommand extends Command
{
    private PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        parent::__construct();
        $this->pluginManager = $pluginManager;
    }

    protected function configure(): void
    {
        $this->addOption(
            'state',
            's',
            InputOption::VALUE_OPTIONAL,
            'Filter by state (enabled, disabled, faulted)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $stateFilter = $input->getOption('state');

        $io->title('Plugin List');

        if ($stateFilter === 'enabled') {
            $plugins = $this->pluginManager->getEnabledPlugins();
        } elseif ($stateFilter === 'disabled') {
            $plugins = $this->pluginManager->getDisabledPlugins();
        } elseif ($stateFilter === 'faulted') {
            $plugins = $this->pluginManager->getFaultedPlugins();
        } else {
            $plugins = $this->pluginManager->getAllPlugins();
        }

        if (count($plugins) === 0) {
            $io->warning('No plugins found');

            return Command::SUCCESS;
        }

        $stats = $this->pluginManager->getStatistics();
        $io->section('Statistics');
        $io->table(
            ['Total', 'Enabled', 'Disabled', 'Faulted'],
            [[$stats['total'], $stats['enabled'], $stats['disabled'], $stats['faulted']]]
        );

        $io->section('Plugins' . ($stateFilter ? " (state: $stateFilter)" : ''));

        $rows = [];
        foreach ($plugins as $plugin) {
            $state = $plugin->getState();
            $stateLabel = $state->getLabel();

            $stateDisplay = match ($state->value) {
                'enabled' => "<fg=green>$stateLabel</>",
                'disabled' => "<fg=yellow>$stateLabel</>",
                'faulted' => "<fg=red>$stateLabel</>",
                default => $stateLabel,
            };

            $rows[] = [
                $plugin->getName(),
                $plugin->getDisplayName(),
                $plugin->getVersion(),
                $plugin->getAuthor(),
                $stateDisplay,
                implode(', ', $plugin->getCapabilities()),
            ];
        }

        $io->table(
            ['Name', 'Display Name', 'Version', 'Author', 'State', 'Capabilities'],
            $rows
        );

        $io->success(sprintf('Found %d plugin(s)', count($plugins)));

        return Command::SUCCESS;
    }
}
