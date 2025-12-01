<?php

namespace App\Core\Command\Plugin;

use App\Core\Service\Plugin\PluginScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:plugin:scan',
    description: 'Scan plugins directory and display discovered plugins',
    aliases: ['plugin:scan']
)]
class PluginScanCommand extends Command
{
    private PluginScanner $pluginScanner;

    public function __construct(PluginScanner $pluginScanner)
    {
        parent::__construct();
        $this->pluginScanner = $pluginScanner;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Plugin Scanner');
        $io->text('Scanning: ' . $this->pluginScanner->getPluginsDirectory());

        $allPlugins = $this->pluginScanner->scan();

        if (count($allPlugins) === 0) {
            $io->warning('No plugins found in plugins directory');

            return Command::SUCCESS;
        }

        $summary = $this->pluginScanner->getSummary();
        $io->section('Summary');
        $io->table(
            ['Total', 'Valid', 'Invalid'],
            [[$summary['total'], $summary['valid'], $summary['invalid']]]
        );

        $validPlugins = $this->pluginScanner->scanValid();
        if (count($validPlugins) > 0) {
            $io->section('Valid Plugins (' . count($validPlugins) . ')');

            $rows = [];
            foreach ($validPlugins as $data) {
                $manifest = $data['manifest'];
                $rows[] = [
                    $manifest->name,
                    $manifest->displayName,
                    $manifest->version,
                    $manifest->author,
                    implode(', ', $manifest->capabilities),
                ];
            }

            $io->table(
                ['Name', 'Display Name', 'Version', 'Author', 'Capabilities'],
                $rows
            );
        }

        $invalidPlugins = $this->pluginScanner->scanInvalid();
        if (count($invalidPlugins) > 0) {
            $io->section('Invalid Plugins (' . count($invalidPlugins) . ')');

            foreach ($invalidPlugins as $name => $data) {
                $io->error("Plugin: $name");
                $io->listing($data['errors']);
            }
        }

        $io->success('Plugin scan completed');

        return Command::SUCCESS;
    }
}
