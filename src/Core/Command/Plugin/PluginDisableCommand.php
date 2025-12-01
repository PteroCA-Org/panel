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
    name: 'pteroca:plugin:disable',
    description: 'Disable a plugin',
    aliases: ['plugin:disable']
)]
class PluginDisableCommand extends Command
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
                'Plugin name to disable'
            )
            ->addOption(
                'cascade',
                'c',
                InputOption::VALUE_NONE,
                'Also disable all plugins that depend on this plugin'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginName = $input->getArgument('plugin');
        $cascade = $input->getOption('cascade');

        $io->title("Disable Plugin: $pluginName");

        $plugin = $this->pluginManager->getPluginByName($pluginName);
        if ($plugin === null) {
            $io->error("Plugin '$pluginName' not found. Run 'plugin:list' to see available plugins.");

            return Command::FAILURE;
        }

        if ($plugin->getState()->value === 'disabled') {
            $io->warning("Plugin '$pluginName' is already disabled");

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
            ]
        );

        // Check for dependents
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

            if (!$cascade) {
                $io->error('Cannot disable plugin with active dependents. Use --cascade to disable all dependent plugins.');
                return Command::FAILURE;
            }

            $io->section('Cascade Disable');
            $io->text('The following plugins will be disabled in order:');

            // Show in reverse dependency order
            foreach ($enabledDependents as $dep) {
                $io->writeln(sprintf('  • %s', $dep->getDisplayName()));
            }
            $io->writeln(sprintf('  • %s', $plugin->getDisplayName()));

            if (!$io->confirm('Are you sure you want to disable all these plugins?', false)) {
                $io->note('Operation cancelled');
                return Command::SUCCESS;
            }
        }

        try {
            $this->pluginManager->disablePlugin($plugin, $cascade);

            $io->success("Plugin '$pluginName' has been disabled successfully");

            if ($cascade && !empty($enabledDependents)) {
                $io->note(sprintf('Also disabled %d dependent plugin(s)', count($enabledDependents)));
            }

            return Command::SUCCESS;
        } catch (InvalidStateTransitionException $e) {
            $io->error($e->getMessage());
            $io->note("Current state: " . $this->translator->trans($plugin->getState()->getLabel()));

            return Command::FAILURE;
        } catch (PluginDependencyException $e) {
            $io->error("Dependency error: {$e->getMessage()}");

            return Command::FAILURE;
        } catch (Exception $e) {
            $io->error("Failed to disable plugin: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
