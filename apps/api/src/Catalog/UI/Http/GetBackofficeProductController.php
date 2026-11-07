<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\GetProductEditView;
use App\Catalog\Application\Query\ProductEditView;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetBackofficeProductController
{
    use HandleTrait;

    /** Attribute of ProductVoter (Infrastructure): the controller only knows its name. */
    private const string VIEW_PRODUCTS = 'VIEW_PRODUCTS';

    /** A UUID in any letter case; anything else matches no route (404), like an unknown product. */
    private const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/products/{id}', name: 'catalog_backoffice_product', requirements: ['id' => self::UUID], methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getProduct',
        summary: 'Product edit view',
        description: 'A product, also a draft, as the edit form needs it, with its `version`. Every staff member may read it.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Product id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The product.',
                content: new OA\JsonContent(ref: new Model(type: ProductEditViewResponse::class)),
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 404,
                description: 'No such product, or the id is not a UUID (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The keys of the token issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    #[IsGranted(self::VIEW_PRODUCTS)]
    public function __invoke(string $id): JsonResponse
    {
        /** @var ProductEditView $view */
        $view = $this->handle(new GetProductEditView($id));

        $response = new JsonResponse(ProductEditViewResponse::of($view));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
