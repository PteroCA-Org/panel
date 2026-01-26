<?php

namespace App\Core\DTO\Email;

use App\Core\Contract\ProductInterface;
use App\Core\Contract\UserInterface;

readonly class PurchaseEmailContextDTO extends EmailContextDTO
{
    public function __construct(
        UserInterface $user,
        string $currency,
        array $serverData,
        array $panelData,
        string $siteUrl,
        private ProductInterface $product,
        private PriceCalculationDTO $priceCalculation,
    ) {
        parent::__construct($user, $currency, $serverData, $panelData, $siteUrl);
    }

    public function getProduct(): ProductInterface
    {
        return $this->product;
    }

    public function getPriceCalculation(): PriceCalculationDTO
    {
        return $this->priceCalculation;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'product' => $this->product,
            'selectedPrice' => $this->priceCalculation->getOriginalPrice(),
            'priceCalculation' => $this->priceCalculation->toArray(),
        ]);
    }
}
