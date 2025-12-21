<?php

namespace App\Core\Service\Pterodactyl;

use App\Core\Exception\Pterodactyl\PterodactylApiException;
use Exception;

readonly class PterodactylExceptionHandler
{
    public function parseException(Exception $exception): array
    {
        $errorData = ['error' => $exception->getMessage()];

        if ($exception instanceof PterodactylApiException && $exception->getResponseBody()) {
            $pterodactylError = json_decode($exception->getResponseBody(), true);

            if (isset($pterodactylError['errors'][0]['detail'])) {
                $errorData['detail'] = $pterodactylError['errors'][0]['detail'];
            }
        }

        return $errorData;
    }
}
