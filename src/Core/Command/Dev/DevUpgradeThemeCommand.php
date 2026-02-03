<?php

namespace App\Core\Command\Dev;

use App\Core\Handler\UpgradeTheme\UpgradeThemeHandler;
use App\Core\Service\Template\TemplateService;
use App\Core\Service\Template\UpgradeThemeService;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:dev:upgrade-theme',
    description: 'Upgrades a theme from pre-0.6.3 to 0.6.3+ context-based structure',
)]
class DevUpgradeThemeCommand extends Command
{
    public function __construct(
        private readonly UpgradeThemeHandler $upgradeThemeHandler,
        private readonly TemplateService $templateService,
        private readonly UpgradeThemeService $upgradeThemeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('theme-name', InputArgument::OPTIONAL, 'Theme name to upgrade')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompts')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Skip backup creation (not recommended)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('PteroCA Theme Upgrade Tool');

        $themeName = $input->getArgument('theme-name');
        if (!$themeName) {
            $themeName = $this->selectThemeInteractively($io);
            if (!$themeName) {
                return Command::SUCCESS;
            }
        }

        if (!$this->upgradeThemeService->isValidTheme($themeName)) {
            $io->error(sprintf('Theme "%s" does not exist or is invalid.', $themeName));
            $this->showAvailableThemes($io);
            return Command::FAILURE;
        }

        if (!$this->upgradeThemeService->needsUpgrade($themeName)) {
            $io->note(sprintf('Theme "%s" is already upgraded to 0.6.3+ structure. No action needed.', $themeName));
            return Command::SUCCESS;
        }

        $this->showUpgradePlan($io, $themeName);

        if (!$input->getOption('force')) {
            if (!$io->confirm('Continue?', false)) {
                $io->info('Upgrade cancelled.');
                return Command::SUCCESS;
            }
        }

        try {
            $this->upgradeThemeHandler->setThemeName($themeName);
            $this->upgradeThemeHandler->setOptions([
                'no-backup' => $input->getOption('no-backup'),
            ]);

            $io->section('Upgrading theme');

            if (!$input->getOption('no-backup')) {
                $io->text('Creating backup...');
            }

            $this->upgradeThemeHandler->handle();

            $backupPath = $this->upgradeThemeHandler->getBackupPath();
            if ($backupPath) {
                $io->success(sprintf('Created backup at %s', basename($backupPath)));
            }

            $io->success(sprintf('Theme "%s" successfully upgraded to 0.6.3 structure!', $themeName));

            if ($backupPath) {
                $io->info(sprintf('Backup location: %s', basename($backupPath)));
            }

            return Command::SUCCESS;

        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            if ($this->upgradeThemeHandler->getBackupPath()) {
                $io->warning('Changes have been rolled back from backup.');
            }

            return Command::FAILURE;
        }
    }

    private function selectThemeInteractively(SymfonyStyle $io): ?string
    {
        $allThemes = $this->templateService->getAvailableTemplates();
        $upgradeableThemes = [];

        foreach ($allThemes as $themeName) {
            if ($this->upgradeThemeService->needsUpgrade($themeName)) {
                $metadata = $this->templateService->getRawTemplateInfo($themeName);
                $version = $metadata['version'] ?? '?';
                $pterocaVersion = $metadata['pterocaVersion'] ?? '?';

                $upgradeableThemes[$themeName] = sprintf(
                    '%s (v%s, PteroCA %s)',
                    $themeName,
                    $version,
                    $pterocaVersion
                );
            }
        }

        if (empty($upgradeableThemes)) {
            $io->info('No themes found that need upgrading.');
            return null;
        }

        $io->section('Available themes that need upgrading');

        $choices = array_values($upgradeableThemes);
        $choices[] = 'Exit';

        $selected = $io->choice('Select theme to upgrade', $choices, 'Exit');

        if ($selected === 'Exit') {
            return null;
        }

        return array_search($selected, $upgradeableThemes, true);
    }

    private function showUpgradePlan(SymfonyStyle $io, string $themeName): void
    {
        $metadata = $this->templateService->getRawTemplateInfo($themeName);

        $io->section(sprintf('Theme: %s', $themeName));
        $io->text(sprintf('Version: %s', $metadata['version'] ?? 'unknown'));
        $io->text(sprintf('PteroCA Version: %s', $metadata['pterocaVersion'] ?? 'unknown'));
        $io->newLine();

        $io->text('This command will:');
        $io->listing([
            sprintf('Create backup: themes/%s_backup', $themeName),
            'Create landing context folder',
            'Move panel-related files to panel/ folder',
            'Copy landing templates from default theme',
            'Copy theme assets if they don\'t exist',
            'Update template.json with contexts field',
        ]);

        $io->warning('This operation will modify theme files. A backup will be created.');
    }

    private function showAvailableThemes(SymfonyStyle $io): void
    {
        $themes = $this->templateService->getAvailableTemplates();

        if (!empty($themes)) {
            $io->text('Available themes: ' . implode(', ', $themes));
        }
    }
}
