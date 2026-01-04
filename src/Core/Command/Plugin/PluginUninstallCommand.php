<?php

namespace App\Core\Command\Plugin;

use App\Core\Exception\Plugin\PluginDependencyException;
use App\Core\Service\Plugin\PluginManager;
use App\Core\Service\Plugin\PluginDependencyResolver;
use Exception;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'pteroca:plugin:uninstall',
    description: 'Completely remove a plugin',
    aliases: ['plugin:uninstall']
)]
class PluginUninstallCommand extends Command
{
    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly PluginDependencyResolver $dependencyResolver,
        private readonly TranslatorInterface $translator,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'plugin',
                InputArgument::REQUIRED,
                'Plugin name to uninstall'
            )
            ->addOption(
                'keep-files',
                'k',
                InputOption::VALUE_NONE,
                'Keep plugin files on filesystem, only remove from database'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginName = $input->getArgument('plugin');
        $keepFiles = $input->getOption('keep-files');

        $io->title("Uninstall Plugin: $pluginName");

        // Get plugin from database
        $plugin = $this->pluginManager->getPluginByName($pluginName);
        if ($plugin === null) {
            $io->error("Plugin '$pluginName' not found in database. Run 'plugin:list' to see installed plugins.");
            return Command::FAILURE;
        }

        // Display plugin information
        $pluginPath = $this->projectDir . '/plugins/' . $plugin->getName();

        $io->section('Plugin Information');
        $io->table(
            ['Property', 'Value'],
            [
                ['Name', $plugin->getName()],
                ['Display Name', $plugin->getDisplayName()],
                ['Version', $plugin->getVersion()],
                ['Author', $plugin->getAuthor()],
                ['Current State', $this->translator->trans($plugin->getState()->getLabel())],
                ['Installation Path', $pluginPath],
            ]
        );

        // Check for enabled dependents
        $dependents = $this->dependencyResolver->getDependents($plugin);
        $enabledDependents = array_filter($dependents, fn($p) => $p->isEnabled());

        if (!empty($enabledDependents)) {
            $io->warning(sprintf(
                '%d plugin(s) depend on "%s":',
                count($enabledDependents),
                $plugin->getDisplayName()
            ));

            $dependentList = [];
            foreach ($enabledDependents as $dep) {
                $constraint = $dep->getRequires()[$pluginName] ?? '*';
                $dependentList[] = sprintf(
                    '%s (%s) - Constraint: %s - State: %s',
                    $dep->getDisplayName(),
                    $dep->getName(),
                    $constraint,
                    $this->translator->trans($dep->getState()->getLabel())
                );
            }
            $io->listing($dependentList);

            $io->error('Cannot uninstall plugin with active dependents. Disable or uninstall these plugins first.');
            return Command::FAILURE;
        }

        // Show what will be removed
        $io->section('Uninstallation Details');
        $io->text('This operation will remove:');
        $io->listing([
            'Plugin database record',
            'All plugin settings',
            $keepFiles ? '<fg=yellow>Plugin files will be KEPT</>' : '<fg=red>Plugin files from filesystem</>',
        ]);

        // Confirm uninstallation
        if (!$io->confirm('Are you sure you want to uninstall this plugin?', false)) {
            $io->note('Operation cancelled');
            return Command::SUCCESS;
        }

        // Ask about file deletion if --keep-files not used
        $deleteFiles = !$keepFiles;
        if (!$keepFiles) {
            $deleteFiles = $io->confirm('Do you also want to delete the plugin files from the filesystem?', true);
        }

        // Execute uninstallation
        try {
            $this->pluginManager->deletePlugin($plugin, $deleteFiles);

            if ($deleteFiles) {
                $io->success(sprintf(
                    "Plugin '%s' has been completely uninstalled (database and files removed)",
                    $plugin->getDisplayName()
                ));
            } else {
                $io->success(sprintf(
                    "Plugin '%s' has been uninstalled from database",
                    $plugin->getDisplayName()
                ));
                $io->note(sprintf(
                    "Plugin files remain at: %s",
                    $pluginPath
                ));
            }

            return Command::SUCCESS;
        } catch (PluginDependencyException $e) {
            $io->error("Dependency error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (RuntimeException $e) {
            $io->error("Failed to uninstall plugin: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (Exception $e) {
            $io->error("An unexpected error occurred: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
