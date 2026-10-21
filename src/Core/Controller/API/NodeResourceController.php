<?php

namespace App\Core\Controller\API;

use App\Core\Service\Product\LocationService;
use App\Core\Service\Product\NodeResourceCheckService;
use App\Core\Service\StoreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class NodeResourceController extends AbstractController
{
    private const AVAILABILITY_CACHE_TTL = 60;

    public function __construct(
        private readonly NodeResourceCheckService $nodeResourceCheckService,
        private readonly StoreService $storeService,
        private readonly LocationService $locationService,
        private readonly CacheInterface $cache,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/api/check-node-resources', name: 'api_check_node_resources', methods: ['POST'])]
    public function checkNodeResources(Request $request): JsonResponse
    {
        $productId = $request->request->getInt('productId');
        $nodeId = $request->request->getInt('nodeId');

        if (!$productId || !$nodeId) {
            return new JsonResponse([
                'available' => false,
                'message' => 'Invalid parameters'
            ], 400);
        }

        $product = $this->storeService->getActiveProduct($productId);
        if (!$product || !$this->locationService->validateNodeBelongsToProduct($nodeId, $product)) {
            return new JsonResponse([
                'available' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Cached to avoid forwarding every request to the Pterodactyl API
        $result = $this->cache->get(
            sprintf('node_resource_check_%d_%d', $nodeId, $productId),
            function (ItemInterface $item) use ($nodeId, $product): array {
                $item->expiresAfter(self::AVAILABILITY_CACHE_TTL);

                return $this->nodeResourceCheckService->checkNodeAvailability($nodeId, $product);
            }
        );
        $result['message'] = $this->translator->trans($result['message']);

        return new JsonResponse($result);
    }
}
