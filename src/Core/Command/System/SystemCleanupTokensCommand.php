<?php

namespace App\Core\Command\System;

use App\Core\Service\PurchaseTokenService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pteroca:system:cleanup-tokens',
    description: 'Clean up expired purchase tokens from the database',
    aliases: ['app:cleanup-purchase-tokens']
)]
class SystemCleanupTokensCommand extends Command
{
    public function __construct(
        private readonly PurchaseTokenService $purchaseTokenService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->info('Starting cleanup of expired purchase tokens...');

        try {
            $deletedCount = $this->purchaseTokenService->cleanupExpiredTokens();

            if ($deletedCount > 0) {
                $io->success(sprintf('Successfully deleted %d expired purchase token(s).', $deletedCount));
            } else {
                $io->info('No expired purchase tokens found.');
            }

            return Command::SUCCESS;
        } catch (\Exception $exception) {
            $io->error('Failed to cleanup expired purchase tokens: ' . $exception->getMessage());
            return Command::FAILURE;
        }
    }
}
