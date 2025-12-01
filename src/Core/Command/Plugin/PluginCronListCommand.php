<?php

namespace App\Core\Command\Plugin;

use App\Core\Service\CronScheduler;
use App\Core\Service\Plugin\PluginCronRegistry;
use DateTime;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:plugin:cron:list',
    description: 'List all registered plugin cron tasks',
    aliases: ['plugin:cron:list']
)]
class PluginCronListCommand extends Command
{
    public function __construct(
        private readonly PluginCronRegistry $cronRegistry,
        private readonly CronScheduler $scheduler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('enabled-only', null, InputOption::VALUE_NONE, 'Show only enabled tasks')
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Filter by plugin name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Plugin Cron Tasks');

        $pluginFilter = $input->getOption('plugin');
        $enabledOnly = $input->getOption('enabled-only');

        // Get tasks
        if ($pluginFilter) {
            $tasks = $this->cronRegistry->getTasksByPlugin($pluginFilter);

            if (empty($tasks)) {
                $io->warning(sprintf('No tasks found for plugin "%s"', $pluginFilter));
                return Command::SUCCESS;
            }
        } else {
            $tasks = $this->cronRegistry->getAllTasks();
        }

        // Filter by enabled status if requested
        if ($enabledOnly) {
            $tasks = array_filter($tasks, fn($task) => $task->isEnabled());
        }

        if (empty($tasks)) {
            $io->info('No cron tasks registered.');
            return Command::SUCCESS;
        }

        // Prepare table data
        $rows = [];
        $currentTime = new DateTime();

        foreach ($tasks as $task) {
            $enabled = $task->isEnabled() ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $isDue = $this->scheduler->shouldRun($task, $currentTime) ? '<fg=yellow>DUE NOW</>' : '';

            try {
                $nextRun = $this->scheduler->getNextRunTime($task, $currentTime);
                $nextRunFormatted = $nextRun->format('Y-m-d H:i:s');
            } catch (Exception) {
                $nextRunFormatted = '<fg=red>Invalid schedule</>';
            }

            $rows[] = [
                $task->getName(),
                $task->getSchedule(),
                $nextRunFormatted,
                $enabled,
                $isDue,
                $task->getDescription(),
            ];
        }

        // Display table
        $io->table(
            ['Task Name', 'Schedule', 'Next Run', 'Enabled', 'Status', 'Description'],
            $rows
        );

        $io->writeln(sprintf('Total: <info>%d</info> task(s)', count($tasks)));

        if ($enabledOnly) {
            $totalTasks = count($this->cronRegistry->getAllTasks());
            $disabledCount = $totalTasks - count($tasks);
            if ($disabledCount > 0) {
                $io->writeln(sprintf('<comment>%d disabled task(s) hidden</comment>', $disabledCount));
            }
        }

        return Command::SUCCESS;
    }
}
