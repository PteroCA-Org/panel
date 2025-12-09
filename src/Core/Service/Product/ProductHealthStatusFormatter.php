<?php

namespace App\Core\Service\Product;

use App\Core\Entity\Product;
use App\Core\Enum\ProductHealthStatusEnum;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProductHealthStatusFormatter
{
    public function __construct(
        private readonly NestEggsCacheService $nestEggsCacheService,
    ) {}

    public function getHealthBadgeHtml(Product $product, TranslatorInterface $translator): string
    {
        $status = $this->nestEggsCacheService->checkProductHealth($product);

        return match ($status) {
            ProductHealthStatusEnum::HEALTHY => sprintf(
                '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>%s</span>',
                $translator->trans('pteroca.crud.product.health_status.healthy')
            ),
            ProductHealthStatusEnum::SOME_EGGS_INVALID => sprintf(
                '<span class="badge bg-warning"><i class="fas fa-exclamation-triangle me-1"></i>%s</span>',
                $translator->trans('pteroca.crud.product.health_status.some_eggs_invalid')
            ),
            ProductHealthStatusEnum::ALL_EGGS_INVALID => sprintf(
                '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>%s</span>',
                $translator->trans('pteroca.crud.product.health_status.all_eggs_invalid')
            ),
            ProductHealthStatusEnum::NO_EGGS => sprintf(
                '<span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>%s</span>',
                $translator->trans('pteroca.crud.product.health_status.no_eggs')
            ),
            ProductHealthStatusEnum::NO_PRICES => sprintf(
                '<span class="badge bg-warning"><i class="fas fa-dollar-sign me-1"></i>%s</span>',
                $translator->trans('pteroca.crud.product.health_status.no_prices')
            ),
            ProductHealthStatusEnum::NEST_UNAVAILABLE => sprintf(
                '<span class="badge bg-dark"><i class="fas fa-question-circle me-1"></i>%s</span>',
                $translator->trans('pteroca.crud.product.health_status.nest_unavailable')
            ),
            ProductHealthStatusEnum::UNKNOWN => sprintf(
                '<span class="badge bg-secondary">%s</span>',
                $translator->trans('pteroca.crud.product.health_status.unknown')
            ),
        };
    }
}
