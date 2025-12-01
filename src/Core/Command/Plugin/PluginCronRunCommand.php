<?php

namespace App\Core\Command\Plugin;

use App\Core\Service\CronScheduler;
use DateTime;
use DateTimeInterface;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:plugin:cron:run',
    description: 'Run plugin cron tasks (all due tasks or a specific task)',
    aliases: ['plugin:cron:run']
)]
class PluginCronRunCommand extends Command
{
    public function __construct(
        private readonly CronScheduler $scheduler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('task', InputArgument::OPTIONAL, 'Specific task name to run (format: plugin-name:task-name)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force run even if task is not due')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would run without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $taskName = $input->getArgument('task');
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        $currentTime = new DateTime();

        if ($taskName) {
            // Run specific task
            return $this->runSpecificTask($io, $taskName, $force, $dryRun, $currentTime);
        } else {
            // Run all due tasks
            return $this->runDueTasks($io, $dryRun, $currentTime);
        }
    }

    private function runSpecificTask(
        SymfonyStyle $io,
        string $taskName,
        bool $force,
        bool $dryRun,
        DateTimeInterface $currentTime
    ): int {
        $io->title(sprintf('Running Task: %s', $taskName));

        if ($dryRun) {
            $io->note('DRY RUN MODE - No tasks will be executed');
        }

        if (!$force) {
            $io->warning('Specific task will run regardless of schedule (use --force to suppress this warning)');
        }

        if ($dryRun) {
            $io->success(sprintf('Would execute task: %s', $taskName));
            return Command::SUCCESS;
        }

        try {
            $result = $this->scheduler->runTask($taskName, $currentTime);

            if ($result['status'] === 'success') {
                $io->success(sprintf(
                    'Task executed successfully in %s seconds',
                    $result['duration'] ?? 'unknown'
                ));

                if (isset($result['next_run'])) {
                    $io->writeln(sprintf('Next run: <info>%s</info>', $result['next_run']));
                }

                return Command::SUCCESS;
            } else {
                $io->error(sprintf('Task failed: %s', $result['error'] ?? 'Unknown error'));
                return Command::FAILURE;
            }
        } catch (Exception $e) {
            $io->error(sprintf('Failed to run task: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function runDueTasks(
        SymfonyStyle $io,
        bool $dryRun,
        DateTimeInterface $currentTime
    ): int {
        $io->title('Running Due Plugin Cron Tasks');
        $io->writeln(sprintf('Current time: <info>%s</info>', $currentTime->format('Y-m-d H:i:s')));

        if ($dryRun) {
            $io->note('DRY RUN MODE - No tasks will be executed');
        }

        // Get due tasks
        $dueTasks = $this->scheduler->getDueTasks($currentTime);

        if (empty($dueTasks)) {
            $io->info('No tasks are due at this time.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> due task(s)', count($dueTasks)));
        $io->newLine();

        if ($dryRun) {
            // Show what would be executed
            $table = [];
            foreach ($dueTasks as $task) {
                $table[] = [
                    $task->getName(),
                    $task->getSchedule(),
                    $task->getDescription(),
                ];
            }

            $io->table(['Task Name', 'Schedule', 'Description'], $table);
            $io->success('Dry run completed - no tasks were executed');
            return Command::SUCCESS;
        }

        // Execute due tasks
        $results = $this->scheduler->runDueTasks($currentTime);

        // Display results
        $successCount = 0;
        $failureCount = 0;
        $skippedCount = 0;

        $io->section('Execution Results');

        foreach ($results as $taskName => $result) {
            $status = $result['status'];

            if ($status === 'success') {
                $successCount++;
                $io->writeln(sprintf(
                    '<fg=green>✓</> %s - <info>Success</info> (%.3fs)',
                    $taskName,
                    $result['duration'] ?? 0
                ));
            } elseif ($status === 'error') {
                $failureCount++;
                $io->writeln(sprintf(
                    '<fg=red>✗</> %s - <error>Failed</error>: %s',
                    $taskName,
                    $result['error'] ?? 'Unknown error'
                ));
            } else {
                $skippedCount++;
                $io->writeln(sprintf(
                    '<fg=yellow>⊘</> %s - <comment>%s</comment>',
                    $taskName,
                    $result['reason'] ?? 'Skipped'
                ));
            }
        }

        $io->newLine();

        // Summary
        $io->writeln([
            sprintf('Total executed: <info>%d</info>', $successCount + $failureCount),
            sprintf('Successful: <info>%d</info>', $successCount),
            sprintf('Failed: %s', $failureCount > 0 ? "<error>$failureCount</error>" : '<info>0</info>'),
        ]);

        if ($skippedCount > 0) {
            $io->writeln(sprintf('Skipped: <comment>%d</comment>', $skippedCount));
        }

        if ($failureCount > 0) {
            $io->warning('Some tasks failed to execute');
            return Command::FAILURE;
        }

        $io->success('All due tasks executed successfully');
        return Command::SUCCESS;
    }
}
