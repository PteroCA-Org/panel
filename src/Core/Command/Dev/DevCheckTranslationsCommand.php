<?php

namespace App\Core\Command\Dev;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'pteroca:dev:check-translations',
    description: 'Show missing translations in the files',
    aliases: ['app:show-missing-translations']
)]
class DevCheckTranslationsCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('mainFile', InputArgument::REQUIRED, 'Main file to compare with.');
        $this->addArgument('compareFile', InputArgument::REQUIRED, 'File to compare with the main file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mainFile = $input->getArgument('mainFile');
        $compareFile = $input->getArgument('compareFile');

        if (!file_exists($mainFile)) {
            $io->error(sprintf('Main file "%s" does not exist.', $mainFile));
            return Command::FAILURE;
        }

        if (!file_exists($compareFile)) {
            $io->error(sprintf('Compare file "%s" does not exist.', $compareFile));
            return Command::FAILURE;
        }

        try {
            $mainData = Yaml::parseFile($mainFile);
            $compareData = Yaml::parseFile($compareFile);
        } catch (Exception $e) {
            $io->error('Error parsing YAML files: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $missingKeys = $this->findMissingKeys($mainData, $compareData);

        if (empty($missingKeys)) {
            $io->success('No missing translations found.');
            return Command::SUCCESS;
        } else {
            $io->error(sprintf('Found %d missing translations in "%s":', count($missingKeys), $compareFile));

            $missingKeys = array_filter($missingKeys, function($key) {
                return !empty(trim($key));
            });

            $io->writeln(implode(', ', $missingKeys));
            return Command::FAILURE;
        }
    }

    private function findMissingKeys(array $mainData, array $compareData, string $prefix = ''): array
    {
        $missingKeys = [];

        foreach ($mainData as $key => $value) {
            $currentKey = $prefix ? $prefix . '.' . $key : $key;

            if (is_array($value)) {
                if (!isset($compareData[$key]) || !is_array($compareData[$key])) {
                    $missingKeys[] = $currentKey;
                } else {
                    $subKeys = $this->findMissingKeys($value, $compareData[$key], $currentKey);
                    $missingKeys = array_merge($missingKeys, $subKeys);
                }
            } elseif (!array_key_exists($key, $compareData)) {
                $missingKeys[] = $currentKey;
            }
        }

        return $missingKeys;
    }
}
