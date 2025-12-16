<?php

namespace App\Core\Service\Email;

use App\Core\Service\Pterodactyl\PterodactylRedirectService;

readonly class ClientPanelUrlResolverService
{
    public function __construct(
        private PterodactylRedirectService $pterodactylRedirectService,
    ) {}

    public function resolve(): string
    {
        return $this->pterodactylRedirectService->getBasePanelUrl();
    }
}
