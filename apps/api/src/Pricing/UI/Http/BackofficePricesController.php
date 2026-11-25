<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Port\InvalidPriceListCursor;
use App\Pricing\Application\Query\ListBackofficePrices;
use App\Pricing\Application\Query\PriceList;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class BackofficePricesController
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/prices', name: 'pricing_backoffice_prices', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'listPrices',
        summary: 'Price list',
        description: 'A page of products sorted by name (by default) or by `sort`, then by id, each with its variants in presentation order and the price '
            .'of each variant (null when it has none). Every staff member may read it. The page size counts products, each '
            .'with all its variants. Page with the opaque `next` cursor as `after`, with the same filters and sort (a cursor of another sort is a 400); the first page (without `after`) also has the `total` of the matching products, counted up to 10 000. '
            .'The category filter includes the subcategories and the products that have the category as an additional one; '
            .'an unknown category gives 404. The search term is a literal part of the name, case-insensitive.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'A part of the product name, 2 to 100 characters.', schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 100)),
            new OA\Parameter(name: 'category', in: 'query', required: false, description: 'Category id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'The order of the products: `name` (A to Z, the default), `-name`, `-updatedAt` (changed last first) or `updatedAt` (changed first first); the id breaks the ties.', schema: new OA\Schema(type: 'string', enum: BackofficePricesQuery::SORTS, default: 'name')),
            new OA\Parameter(name: 'productId', in: 'query', required: false, description: 'Only this product (a draft too) with all its variants, as the first and only page (`next` is null, `total` is 1); an unknown product gives an empty list. Not with `q`, `category`, `sort` or `after` (a 400).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'The `next` cursor of the previous page of the same sort.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: BackofficePricesQuery::MAX_CURSOR_LENGTH)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Page size.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: ListBackofficePrices::MAX_PER_PAGE, default: ListBackofficePrices::DEFAULT_PER_PAGE)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of the price list. Possibly an empty one.',
                content: new OA\JsonContent(ref: new Model(type: PriceListResponse::class)),
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid query parameters or paging cursor (problem details with `violations`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 404,
                description: 'The `category` filter names a category that does not exist (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The keys of the token issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    public function __invoke(#[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] BackofficePricesQuery $query = new BackofficePricesQuery()): JsonResponse
    {
        // The validation has accepted only texts here.
        $sort = \is_string($query->sort) ? $query->sort : null;
        $productId = \is_string($query->productId) ? $query->productId : null;

        try {
            /** @var PriceList $list */
            $list = $this->handle(new ListBackofficePrices($query->term(), $query->category, $query->after, $query->perPage, $sort, $productId));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a cursor nobody issued is a bad parameter, a 400.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof InvalidPriceListCursor) {
                throw new ValidationFailedException($query, new ConstraintViolationList([new ConstraintViolation(BackofficePricesQuery::BAD_CURSOR, null, [], $query, 'after', $query->after)]));
            }

            throw $failure;
        }

        $response = new JsonResponse(PriceListResponse::of($list));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
