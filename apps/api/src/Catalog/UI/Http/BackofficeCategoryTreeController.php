<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\CategoryNode;
use App\Catalog\Application\Query\GetCategoryTree;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The category tree for the staff (the filter of the price list): the query of the shop's tree, behind the
 * `backoffice` firewall and never cached.
 */
final class BackofficeCategoryTreeController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/categories', name: 'catalog_backoffice_category_tree', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getBackofficeCategoryTree',
        summary: 'Category tree',
        description: 'The whole category tree, for every staff member. Siblings at every level are sorted by name (Polish collation).',
        tags: ['Catalog'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Root categories with their descendants. Every node has its `slug` (unique among its siblings) and `path` (the slugs from the root to the node joined with `/`, e.g. `turystyka/biwak/namioty`). An empty catalogue gives an empty array.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: CategoryNode::class))),
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The keys of the token issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        /** @var list<CategoryNode> $tree */
        $tree = $this->handle(new GetCategoryTree());

        $response = new JsonResponse($tree);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
