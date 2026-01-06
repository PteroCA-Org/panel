<?php

namespace App\Core\Service\User;

use App\Core\Contract\UserInterface;
use App\Core\Enum\LogActionEnum;
use App\Core\Service\Logs\LogService;
use App\Core\Service\Pterodactyl\PterodactylApplicationService;
use App\Core\Service\Pterodactyl\PterodactylClientApiKeyService;
use App\Core\Exception\CouldNotCreatePterodactylClientApiKeyException;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

readonly class RegeneratePterodactylApiKeyService
{
    public function __construct(
        private PterodactylClientApiKeyService $pterodactylClientApiKeyService,
        private PterodactylApplicationService  $pterodactylApplicationService,
        private EntityManagerInterface         $entityManager,
        private LogService                     $logService,
        private LoggerInterface                $logger,
    ) {
    }

    /**
     * Regenerate Pterodactyl Client API key for a user
     *
     * @param UserInterface $user User to regenerate API key for
     * @param UserInterface $actor User performing the action (for logging)
     * @return array{success: bool, message: string, masked_key?: string, full_key?: string, error?: string}
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function regenerateApiKey(UserInterface $user, UserInterface $actor): array
    {
        if (!$user->getPterodactylUserId()) {
            return [
                'success' => false,
                'message' => 'pteroca.crud.user.pterodactyl_user_not_found',
            ];
        }

        try {
            $oldApiKey = $user->getPterodactylUserApiKey();

            $newApiKey = $this->pterodactylClientApiKeyService->createClientApiKey($user);

            $user->setPterodactylUserApiKey($newApiKey);
            $this->entityManager->flush();

            if ($oldApiKey) {
                try {
                    $oldIdentifier = $this->extractApiKeyIdentifier($oldApiKey);
                    $this->pterodactylApplicationService
                        ->getApplicationApi()
                        ->users()
                        ->deleteApiKeyForUser($user->getPterodactylUserId(), $oldIdentifier);
                } catch (Exception $e) {
                    $this->logger->warning('Failed to delete old API key from Pterodactyl', [
                        'user_id' => $user->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logService->logAction($actor, LogActionEnum::USER_API_KEY_REGENERATED, [
                'user_id' => $user->getId(),
                'user_email' => $user->getEmail(),
            ]);

            $maskedKey = substr($newApiKey, 0, 5) . str_repeat('*', 24);

            return [
                'success' => true,
                'message' => 'pteroca.crud.user.api_key_regenerated_successfully',
                'masked_key' => $maskedKey,
                'full_key' => $newApiKey,
            ];

        } catch (CouldNotCreatePterodactylClientApiKeyException $e) {
            return [
                'success' => false,
                'message' => 'pteroca.crud.user.api_key_generation_failed',
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to regenerate API key', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'pteroca.crud.user.api_key_regeneration_error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function extractApiKeyIdentifier(string $fullApiKey): string
    {
        return substr($fullApiKey, 0, 16);
    }
}
