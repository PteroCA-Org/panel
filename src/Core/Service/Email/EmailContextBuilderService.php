<?php

namespace App\Core\Service\Email;

use App\Core\Contract\ProductInterface;
use App\Core\Contract\ProductPriceInterface;
use App\Core\Contract\UserInterface;
use App\Core\DTO\Email\EmailContextDTO;
use App\Core\DTO\Email\PriceCalculationDTO;
use App\Core\DTO\Email\PurchaseEmailContextDTO;
use App\Core\DTO\Email\RenewalEmailContextDTO;
use App\Core\Entity\Server;
use App\Core\Enum\ProductPriceTypeEnum;
use App\Core\Enum\SettingEnum;
use App\Core\Service\Product\ProductPriceCalculatorService;
use App\Core\Service\Server\ServerService;
use App\Core\Service\Server\ServerSlotPricingService;
use App\Core\Service\SettingService;
use App\Core\Exception\Email\ServerDetailsNotAvailableException;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class EmailContextBuilderService
{
    public function __construct(
        private ServerService                 $serverService,
        private SettingService                $settingService,
        private ClientPanelUrlResolverService $panelUrlResolver,
        private ServerSlotPricingService      $slotPricingService,
        private ProductPriceCalculatorService $priceCalculatorService,
        private TranslatorInterface           $translator,
    ) {}

    /**
     * @throws ServerDetailsNotAvailableException
     */
    public function buildPurchaseContext(
        UserInterface $user,
        Server $server,
        ProductInterface $product,
        ProductPriceInterface $selectedPrice,
        string $pterodactylAccountUsername,
        ?int $slots = null,
    ): PurchaseEmailContextDTO {
        $baseContext = $this->buildBaseContext($user, $server, $pterodactylAccountUsername);
        $priceCalculation = $this->buildPriceCalculation($selectedPrice, $server, $slots);
        
        return new PurchaseEmailContextDTO(
            user: $user,
            currency: $baseContext->getCurrency(),
            serverData: $baseContext->getServerData(),
            panelData: $baseContext->getPanelData(),
            siteUrl: $baseContext->getSiteUrl(),
            product: $product,
            priceCalculation: $priceCalculation,
        );
    }

    /**
     * @throws ServerDetailsNotAvailableException
     */
    public function buildRenewalContext(
        UserInterface $user,
        Server $server,
        ProductInterface $product,
        ProductPriceInterface $selectedPrice,
        string $pterodactylAccountUsername,
    ): RenewalEmailContextDTO {
        $baseContext = $this->buildBaseContext($user, $server, $pterodactylAccountUsername);
        $priceCalculation = $this->buildPriceCalculation($selectedPrice, $server);
        
        return new RenewalEmailContextDTO(
            user: $user,
            currency: $baseContext->getCurrency(),
            serverData: $baseContext->getServerData(),
            panelData: $baseContext->getPanelData(),
            siteUrl: $baseContext->getSiteUrl(),
            product: $product,
            priceCalculation: $priceCalculation,
        );
    }

    /**
     * @throws ServerDetailsNotAvailableException
     */
    private function buildBaseContext(
        UserInterface $user,
        Server $server,
        string $pterodactylAccountUsername,
    ): EmailContextDTO {
        $serverDetails = $this->serverService->getServerDetails($server);
        
        if ($serverDetails === null) {
            throw new ServerDetailsNotAvailableException(
                sprintf('Server details not available for server ID: %d', $server->getId())
            );
        }

        $currency = $this->settingService->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME->value);
        $panelUrl = $this->panelUrlResolver->resolve();
        $siteUrl = (string) $this->settingService->getSetting(SettingEnum::SITE_URL->value);

        $serverData = [
            'ip' => $serverDetails->ip,
            'expiresAt' => $server->getExpiresAt()->format('Y-m-d H:i'),
        ];

        $panelData = [
            'url' => $panelUrl,
            'username' => $pterodactylAccountUsername,
        ];

        return new EmailContextDTO(
            user: $user,
            currency: $currency,
            serverData: $serverData,
            panelData: $panelData,
            siteUrl: $siteUrl,
        );
    }

    private function buildPriceCalculation(
        ProductPriceInterface $price,
        Server $server,
        ?int $slots = null,
    ): PriceCalculationDTO {
        $currency = $this->settingService->getSetting(SettingEnum::INTERNAL_CURRENCY_NAME->value);
        $basePrice = $price->getPrice();
        $pricingType = $price->getType()->value;

        $calculationSlots = $slots;
        if ($calculationSlots === null && $pricingType === ProductPriceTypeEnum::SLOT->value) {
            $calculationSlots = $this->slotPricingService->getServerSlots($server);
        }

        $finalPrice = $this->priceCalculatorService->calculateFinalPrice($price, $calculationSlots);

        $formattedDescription = $this->buildFormattedDescription(
            $basePrice,
            $finalPrice,
            $pricingType,
            $calculationSlots,
            $currency
        );

        return new PriceCalculationDTO(
            originalPrice: $price,
            basePrice: $basePrice,
            finalPrice: $finalPrice,
            pricingType: $pricingType,
            formattedDescription: $formattedDescription,
            slots: $calculationSlots,
        );
    }

    private function buildFormattedDescription(
        float $basePrice,
        float $finalPrice,
        string $pricingType,
        ?int $slots,
        string $currency,
    ): string {
        return match ($pricingType) {
            ProductPriceTypeEnum::SLOT->value => $this->translator->trans('pteroca.email.pricing.slot_format', [
                '{{ slots }}' => $slots ?? 1,
                '{{ price }}' => number_format($basePrice, 2),
                '{{ currency }}' => $currency,
                '{{ total }}' => number_format($finalPrice, 2),
            ]),
            ProductPriceTypeEnum::STATIC->value => $this->translator->trans('pteroca.email.pricing.static_format', [
                '{{ price }}' => number_format($finalPrice, 2),
                '{{ currency }}' => $currency,
            ]),
            ProductPriceTypeEnum::ON_DEMAND->value => $this->translator->trans('pteroca.email.pricing.on_demand_format', [
                '{{ price }}' => number_format($finalPrice, 2),
                '{{ currency }}' => $currency,
            ]),
            default => $this->translator->trans('pteroca.email.pricing.static_format', [
                '{{ price }}' => number_format($finalPrice, 2),
                '{{ currency }}' => $currency,
            ]),
        };
    }
}
