<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ListProducts;
use App\Catalog\Application\Query\ProductPage;
use App\Catalog\Application\Search\SearchUnavailable;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ListProductsController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/products', name: 'catalog_product_list', methods: ['GET'])]
    #[OA\Get(
        operationId: 'listProducts',
        summary: 'Product list',
        description: 'A page of products sorted by name, then by id. The category filter includes the products of all '
            .'its subcategories; an unknown category gives an empty list. A page past the end gives an empty list '
            .'with the real total.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'Category id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number, from 1.', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Page size.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 48, default: 24)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of products.',
                content: new OA\JsonContent(ref: new Model(type: ProductPage::class)),
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid query parameters (problem details with `violations`).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
            new OA\Response(
                response: 503,
                description: 'The search service is unavailable (problem details without technical details).',
                content: new OA\JsonContent(ref: '#/components/schemas/Problem'),
            ),
        ],
    )]
    public function __invoke(#[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ListProductsQuery $query = new ListProductsQuery()): JsonResponse
    {
        try {
            /** @var ProductPage $page */
            $page = $this->handle(new ListProducts($query->category, $query->page, $query->perPage));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; only a transient search failure becomes a 503.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof SearchUnavailable) {
                throw new ServiceUnavailableHttpException(previous: $failure);
            }

            throw $failure;
        }

        $response = new JsonResponse($page);
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}
