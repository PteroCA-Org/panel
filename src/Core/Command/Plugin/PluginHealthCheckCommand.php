<?php

namespace App\Core\Command\Plugin;

use App\Core\Repository\PluginRepository;
use App\Core\Service\Plugin\PluginHealthCheckService;
use App\Core\Trait\PluginSelectionTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:plugin:health:check',
    description: 'Check plugin health status',
    aliases: ['plugin:health:check']
)]
class PluginHealthCheckCommand extends Command
{
    use PluginSelectionTrait;

    public function __construct(
        private readonly PluginRepository $pluginRepository,
        private readonly PluginHealthCheckService $healthCheckService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plugin-name', InputArgument::OPTIONAL, 'Plugin name to check (checks all if omitted)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check all plugins including disabled ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Plugin Health Check');

        $pluginName = $input->getArgument('plugin-name');
        $checkAll = $input->getOption('all');

        // Get plugins to check
        $plugins = $this->getPluginsForCommand($this->pluginRepository, $io, $pluginName, $checkAll);
        if ($plugins === null) {
            return Command::FAILURE;
        }

        if (!$this->validatePluginList($io, $plugins, 'check')) {
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Checking <info>%d</info> plugin(s)...', count($plugins)));
        $io->newLine();

        $healthyCount = 0;
        $unhealthyCount = 0;

        foreach ($plugins as $plugin) {
            $result = $this->healthCheckService->runHealthCheck($plugin);

            $statusIcon = $result->healthy ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $statusText = $result->healthy ? '<fg=green>HEALTHY</>' : '<fg=red>UNHEALTHY</>';

            $io->section(sprintf('%s %s (%s) - %s',
                $statusIcon,
                $plugin->getDisplayName(),
                $plugin->getName(),
                $statusText
            ));

            // Display checks
            $rows = [];
            foreach ($result->checks as $checkName => $passed) {
                $checkIcon = $passed ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $checkStatus = $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>';
                $error = $passed ? '' : ($result->getError($checkName) ?? 'Unknown error');

                $rows[] = [
                    $checkIcon,
                    str_replace('_', ' ', ucwords($checkName, '_')),
                    $checkStatus,
                    $error,
                ];
            }

            $io->table(
                ['', 'Check', 'Status', 'Error'],
                $rows
            );

            if ($result->healthy) {
                $healthyCount++;
            } else {
                $unhealthyCount++;
            }

            $io->writeln(sprintf(
                'Health: <info>%d%%</info> (%d/%d checks passed)',
                (int) $result->getHealthPercentage(),
                $result->getPassedCount(),
                $result->getTotalCount()
            ));
            $io->newLine();
        }

        // Summary
        $io->section('Summary');
        $io->writeln([
            sprintf('Healthy plugins: <fg=green>%d</>', $healthyCount),
            sprintf('Unhealthy plugins: <fg=red>%d</>', $unhealthyCount),
        ]);

        if ($unhealthyCount > 0) {
            $io->warning('Some plugins have health issues. Review and fix them.');
            return Command::FAILURE;
        }

        $io->success('All plugins are healthy!');
        return Command::SUCCESS;
    }
}
