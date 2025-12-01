<?php

namespace App\Core\Command\Plugin;

use App\Core\Exception\Plugin\InvalidStateTransitionException;
use App\Core\Exception\Plugin\PluginDependencyException;
use App\Core\Service\Plugin\PluginManager;
use App\Core\Service\Plugin\PluginDependencyResolver;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'pteroca:plugin:enable',
    description: 'Enable a plugin',
    aliases: ['plugin:enable']
)]
class PluginEnableCommand extends Command
{
    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly PluginDependencyResolver $dependencyResolver,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'plugin',
                InputArgument::REQUIRED,
                'Plugin name to enable'
            )
            ->addOption(
                'with-dependencies',
                'd',
                InputOption::VALUE_NONE,
                'Automatically enable required dependencies'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force enable without dependency checks (DANGEROUS)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginName = $input->getArgument('plugin');
        $withDependencies = $input->getOption('with-dependencies');
        $force = $input->getOption('force');

        $io->title("Enable Plugin: $pluginName");

        $plugin = $this->pluginManager->getPluginByName($pluginName);
        if ($plugin === null) {
            $io->error("Plugin '$pluginName' not found. Run 'plugin:list' to see available plugins.");

            return Command::FAILURE;
        }

        if ($plugin->isEnabled()) {
            $io->warning("Plugin '$pluginName' is already enabled");

            return Command::SUCCESS;
        }

        $io->section('Plugin Information');
        $io->table(
            ['Property', 'Value'],
            [
                ['Name', $plugin->getName()],
                ['Display Name', $plugin->getDisplayName()],
                ['Version', $plugin->getVersion()],
                ['Author', $plugin->getAuthor()],
                ['Current State', $this->translator->trans($plugin->getState()->getLabel())],
                ['Capabilities', implode(', ', $plugin->getCapabilities())],
            ]
        );

        // Check dependencies (unless --force)
        if (!$force) {
            $dependencyErrors = $this->dependencyResolver->validateDependencies($plugin);

            if (!empty($dependencyErrors) && !$withDependencies) {
                $io->error('Cannot enable plugin due to unmet dependencies:');
                $io->listing($dependencyErrors);
                $io->note('Use --with-dependencies to automatically enable required plugins');

                return Command::FAILURE;
            }

            if (!empty($dependencyErrors) && $withDependencies) {
                // Enable dependencies first
                $io->section('Enabling Dependencies');

                $toEnable = $this->dependencyResolver->collectMissingDependencies($plugin);
                $sorted = $this->dependencyResolver->getTopologicalOrder($toEnable);

                $io->text(sprintf('Will enable %d dependencies:', count($sorted)));
                foreach ($sorted as $dep) {
                    $io->writeln(sprintf('  - %s (%s)', $dep->getDisplayName(), $dep->getVersion()));
                }

                if (!$io->confirm('Continue?')) {
                    $io->note('Operation cancelled');
                    return Command::FAILURE;
                }

                foreach ($sorted as $depPlugin) {
                    try {
                        $io->text("Enabling dependency: {$depPlugin->getDisplayName()}...");
                        $this->pluginManager->enablePlugin($depPlugin);
                        $io->writeln(" <fg=green>✓ Done</>");
                    } catch (Exception $e) {
                        $io->error("Failed to enable dependency {$depPlugin->getName()}: {$e->getMessage()}");
                        return Command::FAILURE;
                    }
                }
            }

            // Check circular dependencies
            if ($this->dependencyResolver->hasCircularDependency($plugin)) {
                $path = $this->dependencyResolver->getCircularDependencyPath($plugin);
                $io->error('Circular dependency detected: ' . implode(' → ', $path ?? []));
                return Command::FAILURE;
            }
        } else {
            $io->warning('--force flag used: Skipping dependency checks (this may cause issues!)');
        }

        try {
            $this->pluginManager->enablePlugin($plugin);

            $io->success("Plugin '$pluginName' has been enabled successfully");

            return Command::SUCCESS;
        } catch (InvalidStateTransitionException $e) {
            $io->error($e->getMessage());
            $io->note("Current state: " . $this->translator->trans($plugin->getState()->getLabel()));

            return Command::FAILURE;
        } catch (PluginDependencyException $e) {
            $io->error("Dependency error: {$e->getMessage()}");

            return Command::FAILURE;
        } catch (Exception $e) {
            $io->error("Failed to enable plugin: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
