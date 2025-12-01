<?php

namespace App\Core\Command\Plugin;

use App\Core\Service\Plugin\PluginManager;
use App\Core\Service\Plugin\PluginDependencyResolver;
use Composer\Semver\Semver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'pteroca:plugin:deps',
    description: 'Show plugin dependencies and dependency tree',
    aliases: ['plugin:deps']
)]
class PluginDepsCommand extends Command
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
                InputArgument::OPTIONAL,
                'Plugin name (if not provided, shows all plugins)'
            )
            ->addOption(
                'tree',
                't',
                InputOption::VALUE_NONE,
                'Show full dependency tree'
            )
            ->addOption(
                'dependents',
                'd',
                InputOption::VALUE_NONE,
                'Show plugins that depend on this plugin'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginName = $input->getArgument('plugin');
        $showTree = $input->getOption('tree');
        $showDependents = $input->getOption('dependents');

        if ($pluginName) {
            return $this->showPluginDependencies($io, $pluginName, $showTree, $showDependents);
        }

        return $this->showAllDependencies($io);
    }

    private function showPluginDependencies(
        SymfonyStyle $io,
        string $pluginName,
        bool $showTree,
        bool $showDependents
    ): int {
        $plugin = $this->pluginManager->getPluginByName($pluginName);

        if ($plugin === null) {
            $io->error("Plugin '$pluginName' not found");
            return Command::FAILURE;
        }

        $io->title("Dependencies for: {$plugin->getDisplayName()}");

        // Show direct dependencies
        $requires = $plugin->getRequires();

        if (empty($requires)) {
            $io->success('This plugin has no dependencies');
        } else {
            $io->section('Direct Dependencies');
            $rows = [];

            foreach ($requires as $depName => $constraint) {
                $depPlugin = $this->pluginManager->getPluginByName($depName);
                $installed = $depPlugin ? $depPlugin->getVersion() : 'NOT INSTALLED';
                $state = $depPlugin ? $this->translator->trans($depPlugin->getState()->getLabel()) : '-';
                $compatible = $depPlugin && Semver::satisfies($depPlugin->getVersion(), $constraint) ? '✓' : '✗';

                $rows[] = [
                    $depName,
                    $constraint,
                    $installed,
                    $state,
                    $compatible,
                ];
            }

            $io->table(['Plugin', 'Constraint', 'Installed Version', 'State', 'Compatible'], $rows);

            // Check for circular dependencies
            if ($this->dependencyResolver->hasCircularDependency($plugin)) {
                $path = $this->dependencyResolver->getCircularDependencyPath($plugin);
                $io->warning('Circular dependency detected: ' . implode(' → ', $path ?? []));
            }
        }

        // Show dependency tree
        if ($showTree && !empty($requires)) {
            $io->section('Dependency Tree');
            $tree = $this->dependencyResolver->getDependencyTree($plugin);
            $this->renderTree($io, $tree, '');
        }

        // Show dependents
        if ($showDependents) {
            $dependents = $this->dependencyResolver->getDependents($plugin);

            if (empty($dependents)) {
                $io->note('No plugins depend on this plugin');
            } else {
                $io->section('Dependent Plugins');
                $rows = [];

                foreach ($dependents as $dep) {
                    $constraint = $dep->getRequires()[$pluginName] ?? '*';
                    $rows[] = [
                        $dep->getDisplayName(),
                        $dep->getName(),
                        $constraint,
                        $this->translator->trans($dep->getState()->getLabel()),
                    ];
                }

                $io->table(['Name', 'ID', 'Constraint', 'State'], $rows);
            }
        }

        return Command::SUCCESS;
    }

    private function showAllDependencies(SymfonyStyle $io): int
    {
        $io->title('All Plugin Dependencies');

        $allPlugins = $this->pluginManager->getAllPlugins();

        if (empty($allPlugins)) {
            $io->warning('No plugins found');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($allPlugins as $plugin) {
            $requires = $plugin->getRequires();
            $dependents = $this->dependencyResolver->getDependents($plugin);
            $hasCircular = $this->dependencyResolver->hasCircularDependency($plugin);

            $rows[] = [
                $plugin->getName(),
                $this->translator->trans($plugin->getState()->getLabel()),
                count($requires),
                count($dependents),
                $hasCircular ? '⚠ YES' : '-',
            ];
        }

        $io->table(
            ['Plugin', 'State', 'Dependencies', 'Dependents', 'Circular'],
            $rows
        );

        // Show topological order for enabled plugins
        $enabledPlugins = $this->pluginManager->getEnabledPlugins();
        if (!empty($enabledPlugins)) {
            $io->section('Load Order (Topological Sort)');
            $sorted = $this->dependencyResolver->getTopologicalOrder($enabledPlugins);

            $io->text('Plugins should be loaded in this order:');
            foreach ($sorted as $index => $plugin) {
                $io->writeln(sprintf('  %d. %s (%s)', $index + 1, $plugin->getDisplayName(), $plugin->getName()));
            }
        }

        return Command::SUCCESS;
    }

    private function renderTree(SymfonyStyle $io, array $tree, string $prefix): void
    {
        $keys = array_keys($tree);
        $lastIndex = count($keys) - 1;

        foreach ($keys as $index => $pluginName) {
            $item = $tree[$pluginName];
            $isLast = ($index === $lastIndex);
            $connector = $isLast ? '└── ' : '├── ';
            $nextPrefix = $prefix . ($isLast ? '    ' : '│   ');

            if ($item['plugin'] === null) {
                $io->writeln(sprintf(
                    '%s%s<fg=red>%s (%s) - NOT INSTALLED</>',
                    $prefix,
                    $connector,
                    $pluginName,
                    $item['constraint']
                ));
            } else {
                $plugin = $item['plugin'];
                $constraint = $item['constraint'];

                $io->writeln(sprintf(
                    '%s%s%s (%s) - %s %s',
                    $prefix,
                    $connector,
                    $pluginName,
                    $plugin->getVersion(),
                    $constraint,
                    $this->translator->trans($plugin->getState()->getLabel())
                ));

                if (!empty($item['children'])) {
                    $this->renderTree($io, $item['children'], $nextPrefix);
                }
            }
        }
    }
}
