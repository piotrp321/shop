<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ListProducts;
use App\Catalog\Application\Query\ProductListItem;
use App\Catalog\Application\Query\ProductPage;
use App\Catalog\Application\Search\ProductSort;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Shared\Infrastructure\Http\Cache\CacheTagger;
use App\Shared\Infrastructure\Http\Cache\CacheTags;
use App\Shared\Infrastructure\Http\Cache\PublicReadErrorCacheSubscriber;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ListProductsController
{
    use HandleTrait;

    public function __construct(
        MessageBusInterface $queryBus,
        private readonly CacheTagger $cacheTagger,
        private readonly ValidatorInterface $validator,
    ) {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/products', name: 'catalog_product_list', methods: ['GET'], defaults: [PublicReadErrorCacheSubscriber::ROUTE_DEFAULT => true])]
    #[OA\Get(
        operationId: 'listProducts',
        summary: 'Product list',
        description: 'A page of products, optionally searched by a phrase and narrowed by filters, with the counts that go with '
            .'it (`facets`). The category filter includes the products of all its subcategories; an unknown category gives 404, an '
            .'existing category without products an empty list. A page past the end gives an empty list with the real total. '
            .'Without `sort` the list is by relevance with `q` and by name otherwise. The conditions on variants (`option`, `priceMin`, '
            .'`priceMax`, `available`) all hold for one and the same variant of a product: `option=Kolor:czarne&option=Rozmiar:42` finds '
            .'products with a black variant in size 42, not a black one and a different one in size 42. Prices and availability '
            .'count only variants that can be bought now. Several values of one axis mean "any of them", different axes mean "all". '
            .'Send `option` as a repeated key (`?option=Kolor:czarne&option=Kolor:biały`); the `option[]` form is not accepted. '
            .'The response is public, kept for 60 s and tagged for purging, so a new price or stock level can show in the filters '
            .'after up to a minute.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'Category id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Search phrase, 2 to 100 characters after trimming. Every word must match (name, category names or short description), Polish inflection, missing Polish characters and small typos are tolerated.', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 100)),
            new OA\Parameter(
                name: 'option',
                in: 'query',
                required: false,
                description: 'Wanted variant option as `<axis>:<value>`, split on the first colon (axis 1 to 30 characters, value 1 to 40), repeated for more; at most 50 entries of at most 5 different axes.',
                style: 'form',
                explode: true,
                schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', minLength: 3), maxItems: 50),
            ),
            new OA\Parameter(name: 'priceMin', in: 'query', required: false, description: 'Lowest gross price of a buyable variant, in grosze.', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'priceMax', in: 'query', required: false, description: 'Highest gross price of a buyable variant, in grosze; not lower than `priceMin`.', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'available', in: 'query', required: false, description: 'Only products with a variant that can be bought now. The only accepted value is `true`.', schema: new OA\Schema(type: 'boolean', enum: [true])),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'Order: `relevance` (only with `q`; the default with `q`), `name` (the default without `q`), `price_asc`, `price_desc` (by the lowest price of a buyable variant, products without one last). Ties break by name, then by id.', schema: new OA\Schema(type: 'string', enum: ['relevance', 'name', 'price_asc', 'price_desc'])),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number, from 1.', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Page size.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 48, default: 24)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of products.',
                content: new OA\JsonContent(ref: '#/components/schemas/ProductPage'),
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid query parameters (problem details with `violations`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 401,
                description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 404,
                description: 'The `category` filter names a category that does not exist (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The search service is unavailable, or a token was sent and the keys of its issuer cannot be fetched '
                    .'(problem details without technical details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    public function __invoke(Request $request, #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] ListProductsQuery $query = new ListProductsQuery()): JsonResponse
    {
        $options = OptionFilter::fromQueryString($request->server->getString('QUERY_STRING'));
        $violations = $this->validator->validate($options);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($options, $violations);
        }

        try {
            /** @var ProductPage $page */
            $page = $this->handle(new ListProducts(
                $query->category,
                $query->page,
                $query->perPage,
                $query->phrase(),
                $options->axes(),
                $query->priceMin(),
                $query->priceMax(),
                ListProductsQuery::AVAILABLE === $query->available,
                null === $query->sort ? null : ProductSort::from($query->sort),
            ));
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
        $this->cacheTagger->tag($response, [
            CacheTags::CATALOG,
            ...array_map(static fn (ProductListItem $item): string => CacheTags::product($item->id), $page->items),
        ]);

        return $response;
    }
}
