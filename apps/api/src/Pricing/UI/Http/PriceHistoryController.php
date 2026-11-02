<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\GetPriceHistory;
use App\Pricing\Application\Query\InvalidPriceHistoryCursor;
use App\Pricing\Application\Query\PriceHistoryPage;
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

final class PriceHistoryController
{
    use HandleTrait;

    /** A UUID in any letter case; anything else matches no route (404), like an unknown product. */
    private const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/products/{productId}/price-history', name: 'pricing_price_history', requirements: ['productId' => self::UUID], methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getPriceHistory',
        summary: 'Price history of a product',
        description: 'Every recorded change of the price of a product, newest first, with the price as it was set, '
            .'what made the change and, for a change by staff, who. Every staff member may read it. A product that exists '
            .'but never had a price has an empty history. Page with the opaque `next` cursor as `after`; the total is not counted.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(name: 'productId', in: 'path', required: true, description: 'Product id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'The `next` cursor of the previous page.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: PriceHistoryQuery::MAX_CURSOR_LENGTH)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Page size.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: GetPriceHistory::MAX_PER_PAGE, default: GetPriceHistory::DEFAULT_PER_PAGE)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of the history. Possibly an empty one.',
                content: new OA\JsonContent(ref: new Model(type: PriceHistoryResponse::class)),
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
    public function __invoke(string $productId, #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] PriceHistoryQuery $query = new PriceHistoryQuery()): JsonResponse
    {
        try {
            /** @var PriceHistoryPage $page */
            $page = $this->handle(new GetPriceHistory($productId, $query->after, $query->perPage));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a cursor nobody issued is a bad parameter, a 400.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof InvalidPriceHistoryCursor) {
                throw new ValidationFailedException($query, new ConstraintViolationList([new ConstraintViolation('The paging cursor is invalid.', null, [], $query, 'after', $query->after)]));
            }

            throw $failure;
        }

        $response = new JsonResponse(PriceHistoryResponse::of($page));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
