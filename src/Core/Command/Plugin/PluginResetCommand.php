<?php

namespace App\Core\Command\Plugin;

use App\Core\Exception\Plugin\InvalidStateTransitionException;
use App\Core\Service\Plugin\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'pteroca:plugin:reset',
    description: 'Reset a faulted plugin back to registered state',
    aliases: ['plugin:reset']
)]
class PluginResetCommand extends Command
{
    public function __construct(
        private readonly PluginManager $pluginManager,
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
                'Plugin name to reset'
            )
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command resets a faulted plugin back to registered state.

This is useful when a plugin failed to enable due to a temporary issue (e.g., migration error,
dependency problem) that has since been resolved. After reset, you can try to enable the plugin again.

Usage:
  <info>php %command.full_name% plugin-name</info>

Example:
  <info>php %command.full_name% acme-payments</info>

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pluginName = $input->getArgument('plugin');

        $io->title("Reset Plugin: $pluginName");

        // Find plugin
        $plugin = $this->pluginManager->getPluginByName($pluginName);

        if ($plugin === null) {
            $io->error("Plugin '$pluginName' not found. Run 'plugin:list' to see available plugins.");
            return Command::FAILURE;
        }

        // Check current state
        $currentState = $plugin->getState();

        if (!$currentState->isFaulted()) {
            $io->warning("Plugin '$pluginName' is not in FAULTED state.");
            $io->text("Current state: " . $this->translator->trans($currentState->getLabel()));
            $io->note("Reset command is only for faulted plugins. Use 'plugin:enable' or 'plugin:disable' instead.");
            return Command::SUCCESS;
        }

        // Display plugin information
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

        // Display fault reason prominently
        if ($plugin->getFaultReason()) {
            $io->warning("Previous Fault Reason:");
            $io->text($plugin->getFaultReason());
        }

        // Confirmation
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

        // Perform reset
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
