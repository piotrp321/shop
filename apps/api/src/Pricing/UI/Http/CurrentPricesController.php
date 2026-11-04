<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\CurrentProductPrice;
use App\Pricing\Application\Query\GetCurrentProductPrices;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CurrentPricesController
{
    use HandleTrait;

    private const string PARAMETER = 'productId';

    public function __construct(
        MessageBusInterface $queryBus,
        private readonly ValidatorInterface $validator,
    ) {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/prices', name: 'pricing_current_prices', methods: ['GET'])]
    #[OA\Get(
        operationId: 'getCurrentPrices',
        summary: 'Gross prices of products',
        description: 'The current gross price of each requested product, for the product list. A product without a price, '
            .'with a price at an outdated VAT rate, with more than one variant, or unknown, is left out. Send the ids as a repeated `productId` key '
            .'(`?productId=<uuid>&productId=<uuid>`); the `productId[]` form is not accepted. The response may be cached for 60 seconds, '
            .'so it is not the price to charge: checkout reads prices from the database.',
        tags: ['Pricing'],
        parameters: [
            new OA\Parameter(
                name: self::PARAMETER,
                in: 'query',
                required: true,
                description: 'Product ids (UUID), 1 to 48.',
                style: 'form',
                explode: true,
                schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), minItems: 1, maxItems: CurrentPricesQuery::MAX_PRODUCTS),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The prices found, in the order of the request. Possibly an empty list.',
                content: new OA\JsonContent(ref: '#/components/schemas/PriceList'),
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
        $query = new CurrentPricesQuery(self::productIds($request));
        $violations = $this->validator->validate($query);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($query, $violations);
        }

        // Transitional until the product offers replace this endpoint (VARIANTS-4): a product with exactly one variant
        // has the price of that variant.
        /** @var list<CurrentProductPrice> $prices */
        $prices = $this->handle(new GetCurrentProductPrices($query->productId));

        $response = new JsonResponse(['items' => array_map(
            static fn (CurrentProductPrice $price): array => ['productId' => $price->productId, 'gross' => $price->gross, 'currency' => $price->currency],
            $prices,
        )]);
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }

    /**
     * Every value of the `productId` key of the raw query string, in the order sent. PHP's own parsing keeps only the
     * last value of a repeated key without `[]`, so the string is read here. The collecting stops one value past the
     * limit (the count constraint rejects that), so a long list of ids is neither validated nor handed on in full.
     * The query string itself is split whole: its length is bounded by the web server's request line limit.
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
            if (\count($ids) > CurrentPricesQuery::MAX_PRODUCTS) {
                break;
            }
        }

        return $ids;
    }
}
