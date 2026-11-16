<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\BackofficeCategoryNode;
use App\Catalog\Application\Query\GetBackofficeCategoryTree;
use App\Catalog\UI\Http\Category\BackofficeCategoryNodeResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The category tree for the staff (the filters of the lists and the screen of the categories): the tree of the store
 * with the version of every category and, with `withCounts=true`, its direct products; behind the `backoffice`
 * firewall and never cached.
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
        description: 'The whole category tree, for every staff member. Siblings at every level are in the order of the store: the order set by the staff, then by name (Polish collation).',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'withCounts', in: 'query', required: false, description: 'With `true` every node has the number of its direct products (`productCount`, up to 1000); without it `productCount` is null and nothing is counted.', schema: new OA\Schema(type: 'string', enum: ['true'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Root categories with their descendants. Every node has its `slug` (unique among its siblings), `path` (the slugs from the root to the node joined with `/`, e.g. `turystyka/biwak/namioty`) and `version`. An empty catalogue gives an empty array.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: BackofficeCategoryNodeResponse::class))),
            ),
            new OA\Response(
                response: 400,
                description: '`withCounts` has another value than `true` (problem details with `violations`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
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
    public function __invoke(Request $request): JsonResponse
    {
        $withCounts = $request->query->all()['withCounts'] ?? null;
        if (null !== $withCounts && 'true' !== $withCounts) {
            throw new ValidationFailedException($withCounts, new ConstraintViolationList([new ConstraintViolation('Parametr withCounts przyjmuje tylko wartość true.', null, [], $withCounts, 'withCounts', null)]));
        }

        /** @var list<BackofficeCategoryNode> $tree */
        $tree = $this->handle(new GetBackofficeCategoryTree(null !== $withCounts));

        $response = new JsonResponse(array_map(BackofficeCategoryNodeResponse::of(...), $tree));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
