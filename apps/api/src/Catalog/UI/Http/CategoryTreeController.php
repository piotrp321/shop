<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\CategoryNode;
use App\Catalog\Application\Query\GetCategoryTree;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryTreeController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/categories', name: 'catalog_category_tree', methods: ['GET'])]
    #[OA\Get(
        operationId: 'getCategoryTree',
        summary: 'Category tree',
        description: 'The whole category tree. Siblings at every level are sorted by name (Polish collation).',
        tags: ['Catalog'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Root categories with their descendants. An empty catalogue gives an empty array.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: CategoryNode::class))),
            ),
            new OA\Response(
                response: 401,
                description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        /** @var list<CategoryNode> $tree */
        $tree = $this->handle(new GetCategoryTree());

        $response = new JsonResponse($tree);
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}
