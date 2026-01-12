<?php

namespace App\Core\Controller\API;

use App\Core\Service\Product\NodeResourceCheckService;
use App\Core\Service\StoreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class NodeResourceController extends AbstractController
{
    public function __construct(
        private readonly NodeResourceCheckService $nodeResourceCheckService,
        private readonly StoreService $storeService,
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
        if (!$product) {
            return new JsonResponse([
                'available' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $result = $this->nodeResourceCheckService->checkNodeAvailability($nodeId, $product);

        return new JsonResponse($result);
    }
}
