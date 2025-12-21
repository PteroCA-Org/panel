<?php

namespace App\Core\Trait;

use App\Core\Service\Pterodactyl\PterodactylExceptionHandler;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

trait HandlesPterodactylExceptions
{
    abstract protected function getPterodactylExceptionHandler(): PterodactylExceptionHandler;
    abstract protected function getLogger(): LoggerInterface;

    private function handlePterodactylException(Exception $e, string $action, array $context): JsonResponse
    {
        $this->getLogger()->error("Failed to $action", array_merge($context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]));

        $errorData = $this->getPterodactylExceptionHandler()->parseException($e);

        $response = new JsonResponse();
        $response->setData($errorData);
        $response->setStatusCode(400);

        return $response;
    }
}
