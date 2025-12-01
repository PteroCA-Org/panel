<?php

namespace App\Core\Command\Plugin;

use App\Core\Repository\PluginRepository;
use App\Core\Service\Plugin\ComposerDependencyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command to install Composer dependencies for plugins.
 *
 * This command installs dependencies using production flags:
 * --no-dev, --no-plugins, --no-scripts for security.
 */
#[AsCommand(
    name: 'pteroca:plugin:install-deps',
    description: 'Install Composer dependencies for a plugin',
    aliases: ['plugin:install-deps']
)]
class PluginInstallDepsCommand extends Command
{
    public function __construct(
        private readonly ComposerDependencyManager $composerManager,
        private readonly PluginRepository $pluginRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'plugin',
                InputArgument::OPTIONAL,
                'Plugin name to install dependencies for (omit for --all)'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Install dependencies for all plugins with composer.json'
            )
            ->addOption(
                'clean',
                'c',
                InputOption::VALUE_NONE,
                'Remove vendor/ directory before installation'
            )
            ->setHelp(
                <<<'HELP'
The <info>plugin:install-deps</info> command installs Composer dependencies for plugins.

<info>Examples:</info>
  <comment>php bin/console plugin:install-deps my-plugin</comment>
  Install dependencies for a specific plugin

  <comment>php bin/console plugin:install-deps --all</comment>
  Install dependencies for all plugins with composer.json

  <comment>php bin/console plugin:install-deps my-plugin --clean</comment>
  Clean install (removes vendor/ first)

<info>Security:</info>
Dependencies are installed with production flags:
  - --no-dev (exclude development dependencies)
  - --no-plugins (disable Composer plugins)
  - --no-scripts (disable post-install scripts)
  - --prefer-dist (use distribution packages)
  - --classmap-authoritative (optimize autoloader)

<info>Requirements:</info>
  - Plugin must have composer.json
  - Plugin must have composer.lock (for reproducible builds)
  - composer command must be available in PATH
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginName = $input->getArgument('plugin');
        $all = $input->getOption('all');
        $clean = $input->getOption('clean');

        // Validate: either plugin name or --all must be provided
        if (!$pluginName && !$all) {
            $io->error('Either provide a plugin name or use --all flag');
            return Command::FAILURE;
        }

        if ($pluginName && $all) {
            $io->error('Cannot use both plugin name and --all flag');
            return Command::FAILURE;
        }

        // Install for all plugins
        if ($all) {
            return $this->installForAllPlugins($io, $clean);
        }

        // Install for specific plugin
        return $this->installForPlugin($io, $pluginName, $clean);
    }

    /**
     * Install dependencies for a specific plugin.
     */
    private function installForPlugin(SymfonyStyle $io, string $pluginName, bool $clean): int
    {
        $io->title("Install Composer Dependencies: $pluginName");

        // Find plugin
        $plugin = $this->pluginRepository->findOneBy(['name' => $pluginName]);

        if ($plugin === null) {
            $io->error("Plugin '$pluginName' not found. Run 'plugin:list' to see available plugins.");
            return Command::FAILURE;
        }

        // Check if plugin has composer.json
        if (!$this->composerManager->hasComposerJson($plugin)) {
            $io->warning("Plugin '$pluginName' does not have composer.json file");
            $io->note('This plugin does not use Composer dependencies');
            return Command::SUCCESS;
        }

        // Display plugin info
        $io->section('Plugin Information');
        $io->table(
            ['Property', 'Value'],
            [
                ['Name', $plugin->getName()],
                ['Display Name', $plugin->getDisplayName()],
                ['Version', $plugin->getVersion()],
                ['Has composer.json', '✓ Yes'],
                ['Has composer.lock', $this->composerManager->hasComposerLock($plugin) ? '✓ Yes' : '✗ No'],
                ['Has vendor/', $this->composerManager->hasVendorDirectory($plugin) ? '✓ Yes' : '✗ No'],
                ['Clean install', $clean ? '✓ Yes (will remove vendor/)' : '✗ No'],
            ]
        );

        // Check for composer.lock
        if (!$this->composerManager->hasComposerLock($plugin)) {
            $io->error('composer.lock file is missing');
            $io->note([
                'composer.lock is required for reproducible builds.',
                'Run the following in your plugin directory:',
                '  cd plugins/' . $pluginName,
                '  composer install',
                '  git add composer.lock',
                '  git commit -m "Add composer.lock"',
            ]);
            return Command::FAILURE;
        }

        // Validate composer files
        $io->section('Validating Composer Files');
        $validationIssues = $this->composerManager->validateComposerFiles($plugin);

        if (!empty($validationIssues)) {
            $io->warning('Found validation issues:');
            foreach ($validationIssues as $issue) {
                $io->writeln(sprintf(
                    '  [%s] %s',
                    $issue['severity'],
                    $issue['message']
                ));
            }

            // Fail on HIGH severity
            $highIssues = array_filter($validationIssues, fn($i) => $issue['severity'] === 'HIGH');
            if (!empty($highIssues)) {
                $io->error('Cannot install dependencies due to validation errors');
                return Command::FAILURE;
            }
        } else {
            $io->success('Composer files validated successfully');
        }

        // Install dependencies
        $io->section('Installing Dependencies');
        $io->text([
            'Running: composer install',
            'Flags: --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist',
            'Timeout: 5 minutes',
        ]);

        try {
            $io->progressStart();
            $this->composerManager->installDependencies($plugin, $clean);
            $io->progressFinish();

            $io->newLine();
            $io->success("Dependencies installed successfully for plugin '$pluginName'");

            // Show installed packages
            $packages = $this->composerManager->getInstalledPackages($plugin);
            if (!empty($packages)) {
                $io->section('Installed Packages');
                $tableRows = [];
                foreach ($packages as $packageName => $packageInfo) {
                    $version = $packageInfo['pretty_version'] ?? $packageInfo['version'] ?? 'unknown';
                    $tableRows[] = [$packageName, $version];
                }
                $io->table(['Package', 'Version'], $tableRows);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to install dependencies: ' . $e->getMessage());
            $io->note([
                'Check the logs for detailed error information:',
                '  var/log/plugin.log',
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Install dependencies for all plugins with composer.json.
     */
    private function installForAllPlugins(SymfonyStyle $io, bool $clean): int
    {
        $io->title('Install Composer Dependencies for All Plugins');

        // Find all plugins
        $allPlugins = $this->pluginRepository->findAll();

        // Filter plugins with composer.json
        $pluginsWithComposer = array_filter(
            $allPlugins,
            fn($plugin) => $this->composerManager->hasComposerJson($plugin)
        );

        if (empty($pluginsWithComposer)) {
            $io->info('No plugins with composer.json found');
            return Command::SUCCESS;
        }

        $io->section('Plugins with Composer Dependencies');
        $io->listing(array_map(
            fn($plugin) => sprintf(
                '%s (%s) - %s',
                $plugin->getDisplayName(),
                $plugin->getName(),
                $this->composerManager->hasVendorDirectory($plugin) ? 'vendor/ exists' : 'vendor/ missing'
            ),
            $pluginsWithComposer
        ));

        // Ask for confirmation
        if (!$io->confirm(
            sprintf('Install dependencies for %d plugin(s)?', count($pluginsWithComposer)),
            true
        )) {
            $io->note('Installation cancelled');
            return Command::SUCCESS;
        }

        // Install for each plugin
        $io->progressStart(count($pluginsWithComposer));
        $successCount = 0;
        $failureCount = 0;
        $skippedCount = 0;
        $failures = [];

        foreach ($pluginsWithComposer as $plugin) {
            $pluginName = $plugin->getName();

            try {
                // Check for composer.lock
                if (!$this->composerManager->hasComposerLock($plugin)) {
                    $io->progressAdvance();
                    $skippedCount++;
                    $failures[] = [
                        'plugin' => $pluginName,
                        'reason' => 'composer.lock missing',
                    ];
                    continue;
                }

                // Validate composer files
                $validationIssues = $this->composerManager->validateComposerFiles($plugin);
                $highIssues = array_filter($validationIssues, fn($i) => ($i['severity'] ?? '') === 'HIGH');

                if (!empty($highIssues)) {
                    $io->progressAdvance();
                    $skippedCount++;
                    $failures[] = [
                        'plugin' => $pluginName,
                        'reason' => 'Validation failed',
                    ];
                    continue;
                }

                // Install dependencies
                $this->composerManager->installDependencies($plugin, $clean);
                $successCount++;
            } catch (\Exception $e) {
                $failureCount++;
                $failures[] = [
                    'plugin' => $pluginName,
                    'reason' => $e->getMessage(),
                ];
            } finally {
                $io->progressAdvance();
            }
        }

        $io->progressFinish();
        $io->newLine(2);

        // Display results
        $io->section('Installation Results');
        $io->table(
            ['Status', 'Count'],
            [
                ['✓ Success', $successCount],
                ['✗ Failed', $failureCount],
                ['⊘ Skipped', $skippedCount],
            ]
        );

        // Display failures
        if (!empty($failures)) {
            $io->section('Failures and Skipped');
            $io->table(
                ['Plugin', 'Reason'],
                array_map(
                    fn($failure) => [$failure['plugin'], $failure['reason']],
                    $failures
                )
            );
        }

        if ($failureCount > 0 || $skippedCount > 0) {
            $io->warning(sprintf(
                'Installation completed with %d failure(s) and %d skipped',
                $failureCount,
                $skippedCount
            ));
            return Command::FAILURE;
        }

        $io->success('All dependencies installed successfully');
        return Command::SUCCESS;
    }
}
