<?php

namespace App\Core\EventSubscriber\Product;

use App\Core\Entity\Product;
use App\Core\Service\Product\NestEggsCacheService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

#[AsDoctrineListener(event: Events::postLoad)]
readonly class ProductEventSubscriber
{
    public function __construct(
        private NestEggsCacheService $nestEggsCacheService,
        private LoggerInterface $logger,
    ) {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof Product) {
            return;
        }

        $this->sanitizeProductEggs($entity);
    }

    private function sanitizeProductEggs(Product $product): void
    {
        $currentEggs = $product->getEggs();

        if (empty($currentEggs)) {
            return;
        }

        $nestId = $product->getNest();

        if (empty($nestId)) {
            return;
        }

        try {
            $validEggIds = $this->nestEggsCacheService->getEggIdsForNest($nestId);

            $sanitizedEggs = array_filter($currentEggs, function ($eggId) use ($validEggIds) {
                return in_array($eggId, $validEggIds);
            });

            $sanitizedEggs = array_values($sanitizedEggs);

            if (count($sanitizedEggs) !== count($currentEggs)) {
                $removedCount = count($currentEggs) - count($sanitizedEggs);

                $product->setSanitizedEggsCount($removedCount);
                $product->setEggs($sanitizedEggs);

                $this->sanitizeEggsConfiguration($product, $sanitizedEggs);
            }
        } catch (\Exception $e) {
            // If we can't validate (API error, etc.), don't modify the product
            // The regular validation will handle the error during save
            $this->logger->warning('Failed to sanitize Product eggs on load', [
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sanitizeEggsConfiguration(Product $product, array $validEggIds): void
    {
        $eggsConfig = $product->getEggsConfiguration();

        if (empty($eggsConfig)) {
            return;
        }

        try {
            $config = json_decode($eggsConfig, true);

            if (!is_array($config)) {
                return;
            }

            // Remove configurations for eggs that no longer exist
            $sanitizedConfig = array_filter($config, function ($key) use ($validEggIds) {
                return in_array((int) $key, $validEggIds);
            }, ARRAY_FILTER_USE_KEY);

            $product->setEggsConfiguration(json_encode($sanitizedConfig));
        } catch (\Exception $e) {
            // If JSON parsing fails, leave it as-is
            $this->logger->warning('Failed to sanitize eggs configuration', [
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
