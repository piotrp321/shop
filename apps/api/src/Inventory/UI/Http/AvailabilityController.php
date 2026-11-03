<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Query\GetStockLevels;
use App\Inventory\Application\Query\StockLevel;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AvailabilityController
{
    use HandleTrait;

    private const string PARAMETER = 'productId';

    public function __construct(
        MessageBusInterface $queryBus,
        private readonly ValidatorInterface $validator,
    ) {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/availability', name: 'inventory_availability', methods: ['GET'])]
    #[OA\Get(
        operationId: 'getAvailability',
        summary: 'Availability of products',
        description: 'The availability level of each requested product, for the product list. A product without a stock record, '
            .'or unknown, is `out_of_stock`. The number of units is never returned. Send the ids as a repeated `productId` key '
            .'(`?productId=<uuid>&productId=<uuid>`); the `productId[]` form is not accepted. The response may be cached for '
            .'60 seconds, so it is not what decides a purchase: the reservation at checkout does.',
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(
                name: self::PARAMETER,
                in: 'query',
                required: true,
                description: 'Product ids (UUID), 1 to 48.',
                style: 'form',
                explode: true,
                schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), minItems: 1, maxItems: AvailabilityQuery::MAX_PRODUCTS),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The level of every requested product, in the order of the request; a repeated id answers once.',
                content: new OA\JsonContent(ref: new Model(type: AvailabilityListResponse::class)),
            ),
            new OA\Response(
                response: 400,
                description: 'No id, more than 48 ids, or an id that is not a UUID (problem details with `violations`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 401,
                description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without technical details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $query = new AvailabilityQuery(self::productIds($request));
        $violations = $this->validator->validate($query);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($query, $violations);
        }

        /** @var list<StockLevel> $levels */
        $levels = $this->handle(new GetStockLevels($query->productId));

        $response = new JsonResponse(new AvailabilityListResponse(array_map(AvailabilityItemResponse::of(...), $levels)));
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }

    /**
     * Every value of the `productId` key of the raw query string, in the order sent. PHP's own parsing keeps only the
     * last value of a repeated key without `[]`, so the string is read here. The collecting stops one value past the
     * limit (the count constraint rejects that), so a long list of ids is neither validated nor handed on in full.
     *
     * @return list<string>
     */
    private static function productIds(Request $request): array
    {
        $ids = [];
        foreach (explode('&', $request->server->getString('QUERY_STRING')) as $pair) {
            [$name, $value] = [...explode('=', $pair, 2), ''];
            if (self::PARAMETER !== urldecode($name)) {
                continue;
            }
            $ids[] = urldecode($value);
            if (\count($ids) > AvailabilityQuery::MAX_PRODUCTS) {
                break;
            }
        }

        return $ids;
    }
}
