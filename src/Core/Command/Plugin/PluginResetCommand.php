<?php

namespace App\Core\Command\Plugin;

use App\Core\Exception\Plugin\InvalidStateTransitionException;
use App\Core\Service\Plugin\ManifestParser;
use App\Core\Service\Plugin\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'pteroca:plugin:reset',
    description: 'Reset a faulted plugin or register a discovered plugin',
    aliases: ['plugin:reset']
)]
class PluginResetCommand extends Command
{
    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly TranslatorInterface $translator,
        private readonly ManifestParser $manifestParser,
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
                'Plugin name to reset'
            )
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command resets a faulted plugin or registers a discovered plugin.

Use cases:
1. Reset a FAULTED plugin back to REGISTERED state
2. Register a plugin found on filesystem but not in database (DISCOVERED state)

Usage:
  <info>php %command.full_name% plugin-name</info>

Examples:
  # Register a discovered plugin
  <info>php %command.full_name% paypal-payment</info>

  # Reset a faulted plugin
  <info>php %command.full_name% broken-plugin</info>

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginName = $input->getArgument('plugin');

        $io->title("Reset Plugin: $pluginName");

        $plugin = $this->pluginManager->getPluginByName($pluginName);

        if ($plugin === null) {
            $io->note("Plugin not found in database. Checking filesystem...");

            $pluginPath = $this->projectDir . '/plugins/' . $pluginName;

            if (!is_dir($pluginPath)) {
                $io->error("Plugin '$pluginName' not found in database or filesystem.");
                return Command::FAILURE;
            }

            try {
                $manifest = $this->manifestParser->parseFromDirectory($pluginPath);
            } catch (\Exception $e) {
                $io->error("Failed to parse plugin manifest: " . $e->getMessage());
                return Command::FAILURE;
            }

            $io->section('Registering Plugin from Filesystem');
            $io->text([
                "Plugin found on filesystem but not in database.",
                "This will register the plugin with REGISTERED state.",
                "",
                "Plugin: {$manifest->displayName}",
                "Version: {$manifest->version}",
                "Author: {$manifest->author}",
            ]);

            try {
                $registeredPlugin = $this->pluginManager->registerPlugin($pluginPath, $manifest);

                $io->success("Plugin '$pluginName' registered successfully!");
                $io->text([
                    "The plugin is now in REGISTERED state.",
                    "You can enable it using:",
                    "  php bin/console plugin:enable $pluginName",
                ]);

                return Command::SUCCESS;
            } catch (\Exception $e) {
                $io->error("Failed to register plugin: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $currentState = $plugin->getState();

        if (!$currentState->isFaulted()) {
            $io->warning("Plugin '$pluginName' is not in FAULTED state.");
            $io->text("Current state: " . $this->translator->trans($currentState->getLabel()));
            $io->note("Reset command is only for faulted plugins. Use 'plugin:enable' or 'plugin:disable' instead.");
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
                ['Current State', $this->translator->trans($currentState->getLabel())],
                ['Fault Reason', $plugin->getFaultReason() ?? 'N/A'],
            ]
        );

        if ($plugin->getFaultReason()) {
            $io->warning("Previous Fault Reason:");
            $io->text($plugin->getFaultReason());
        }

        $io->section('Reset Action');
        $io->text([
            'This will:',
            '  • Reset plugin state from FAULTED to REGISTERED',
            '  • Clear the fault reason',
            '  • Allow you to try enabling the plugin again',
            '',
            'Make sure you have fixed the issue that caused the fault before proceeding.',
        ]);

        if (!$io->confirm('Do you want to reset this plugin?', false)) {
            $io->note('Operation cancelled');
            return Command::SUCCESS;
        }

        try {
            $this->pluginManager->resetPlugin($plugin);

            $io->success("Plugin '$pluginName' has been reset successfully");
            $io->text("The plugin is now in REGISTERED state. You can try to enable it again using:");
            $io->text("  php bin/console plugin:enable $pluginName");

            return Command::SUCCESS;
        } catch (InvalidStateTransitionException $e) {
            $io->error("Cannot reset plugin: " . $e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error("Failed to reset plugin: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
