<?php

namespace App\Core\Command\Plugin;

use App\Core\Repository\PluginRepository;
use App\Core\Service\Plugin\PluginSecurityValidator;
use App\Core\Trait\PluginSelectionTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:plugin:security:scan',
    description: 'Scan plugins for security issues',
    aliases: ['plugin:security:scan']
)]
class PluginSecurityScanCommand extends Command
{
    use PluginSelectionTrait;

    public function __construct(
        private readonly PluginRepository $pluginRepository,
        private readonly PluginSecurityValidator $securityValidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('plugin-name', InputArgument::OPTIONAL, 'Plugin name to scan (scans all if omitted)')
            ->addOption('severity', 's', InputOption::VALUE_REQUIRED, 'Filter by severity level (critical, high, medium, low)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Scan all plugins including disabled ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Plugin Security Scanner');

        $pluginName = $input->getArgument('plugin-name');
        $severityFilter = $input->getOption('severity');
        $scanAll = $input->getOption('all');
        $verbose = $output->isVerbose();

        // Validate severity filter
        $validSeverities = ['critical', 'high', 'medium', 'low'];
        if ($severityFilter && !in_array($severityFilter, $validSeverities, true)) {
            $io->error(sprintf('Invalid severity level. Valid options: %s', implode(', ', $validSeverities)));
            return Command::FAILURE;
        }

        // Get plugins to scan
        $plugins = $this->getPluginsForCommand($this->pluginRepository, $io, $pluginName, $scanAll);
        if ($plugins === null) {
            return Command::FAILURE;
        }

        if (!$this->validatePluginList($io, $plugins, 'scan')) {
            return Command::SUCCESS;
        }

        // Scan plugins
        $io->writeln(sprintf('Scanning <info>%d</info> plugin(s)...', count($plugins)));
        $io->newLine();

        $totalIssues = 0;
        $criticalCount = 0;
        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;

        foreach ($plugins as $plugin) {
            $io->section(sprintf('Plugin: %s (%s)', $plugin->getDisplayName(), $plugin->getName()));

            // Run security scan
            $securityCheckResult = $this->securityValidator->validate($plugin);
            $issues = $securityCheckResult->issues;

            // Filter by severity if requested
            if ($severityFilter) {
                $issues = array_filter($issues, fn($issue) => $issue['severity'] === $severityFilter);
            }

            if (empty($issues)) {
                $io->success('No security issues found');
                continue;
            }

            // Count by severity
            foreach ($issues as $issue) {
                match ($issue['severity']) {
                    'critical' => $criticalCount++,
                    'high' => $highCount++,
                    'medium' => $mediumCount++,
                    'low' => $lowCount++,
                    default => null,
                };
            }

            $totalIssues += count($issues);

            // Display issues
            $io->warning(sprintf('Found %d security issue(s)', count($issues)));

            if ($verbose) {
                // Detailed output
                foreach ($issues as $issue) {
                    $this->displayIssueDetailed($io, $issue);
                }
            } else {
                // Table output
                $this->displayIssuesTable($io, $issues);
            }

            $io->newLine();
        }

        // Summary
        $io->section('Summary');
        $io->writeln(sprintf('Total issues found: <info>%d</info>', $totalIssues));

        if ($totalIssues > 0) {
            $io->newLine();
            $io->writeln([
                sprintf('  <fg=red>Critical:</> %d', $criticalCount),
                sprintf('  <fg=yellow>High:</> %d', $highCount),
                sprintf('  <fg=blue>Medium:</> %d', $mediumCount),
                sprintf('  <comment>Low:</> %d', $lowCount),
            ]);

            $io->newLine();

            if ($criticalCount > 0) {
                $io->error('Critical security issues found! These must be fixed before enabling plugins.');
                return Command::FAILURE;
            }

            if ($highCount > 0) {
                $io->warning('High severity issues found. Review and fix these as soon as possible.');
            }
        } else {
            $io->success('No security issues found in scanned plugins.');
        }

        return Command::SUCCESS;
    }

    private function displayIssuesTable(SymfonyStyle $io, array $issues): void
    {
        $rows = [];

        foreach ($issues as $issue) {
            $severity = $this->formatSeverity($issue['severity']);

            $rows[] = [
                $severity,
                $issue['type'],
                $issue['file'] ?? 'N/A',
                $issue['line'] ?? 'N/A',
                $issue['message'],
            ];
        }

        $io->table(
            ['Severity', 'Type', 'File', 'Line', 'Message'],
            $rows
        );
    }

    private function displayIssueDetailed(SymfonyStyle $io, array $issue): void
    {
        $severity = $this->formatSeverity($issue['severity']);

        $io->writeln([
            '',
            sprintf('  %s <options=bold>%s</>', $severity, strtoupper($issue['type'])),
            sprintf('  File: <comment>%s</comment>', $issue['file'] ?? 'N/A'),
            sprintf('  Line: <comment>%s</comment>', $issue['line'] ?? 'N/A'),
            sprintf('  Message: %s', $issue['message']),
        ]);

        if (!empty($issue['suggestion'])) {
            $io->writeln(sprintf('  <info>Suggestion:</> %s', $issue['suggestion']));
        }

        if (!empty($issue['code_snippet'])) {
            $io->writeln([
                '  <info>Code:</info>',
                sprintf('    <comment>%s</comment>', $issue['code_snippet']),
            ]);
        }
    }

    private function formatSeverity(string $severity): string
    {
        return match ($severity) {
            'critical' => '<fg=red;options=bold>CRITICAL</>',
            'high' => '<fg=yellow;options=bold>HIGH</>',
            'medium' => '<fg=blue>MEDIUM</>',
            'low' => '<comment>LOW</>',
            default => $severity,
        };
    }
}
