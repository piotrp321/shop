<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Application\Query\GetProductOffers;
use App\Cart\Application\View\ProductOfferView;
use App\Shared\Infrastructure\Http\Cache\CacheTagger;
use App\Shared\Infrastructure\Http\Cache\CacheTags;
use App\Shared\Infrastructure\Http\Cache\PublicReadErrorCacheSubscriber;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The offers of the products of a page of the store list: one public, cacheable read for the whole page.
 */
final class ProductOffersController
{
    use HandleTrait;

    private const string PARAMETER = 'productId';

    public function __construct(
        #[Autowire(service: 'query.bus')] MessageBusInterface $queryBus,
        private readonly ValidatorInterface $validator,
        private readonly CacheTagger $cacheTagger,
    ) {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/product-offers', name: 'cart_product_offers', methods: ['GET'], defaults: [PublicReadErrorCacheSubscriber::ROUTE_DEFAULT => true])]
    #[OA\Get(
        operationId: 'getProductOffers',
        summary: 'Offers of products of the list',
        description: 'For each requested product its variants (label, current gross price, availability level, whether one unit can be '
            .'bought now), the lowest price among the purchasable variants (`fromGross`, with `hasPriceRange` when their prices differ) '
            .'and the best level among them; a product without a purchasable variant, also one unknown to Catalog, has no price and is '
            .'`out_of_stock`. The number of units is never returned. Send the ids as a repeated `productId` key '
            .'(`?productId=<uuid>&productId=<uuid>`); the `productId[]` form is not accepted. The response may be cached for 60 seconds, '
            .'so it does not decide a purchase: an addition to the cart checks the price and the stock again.',
        tags: ['Cart'],
        parameters: [
            new OA\Parameter(
                name: self::PARAMETER,
                in: 'query',
                required: true,
                description: 'Product ids (UUID), 1 to 48.',
                style: 'form',
                explode: true,
                schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), minItems: 1, maxItems: GetProductOffers::MAX_PRODUCTS),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'One offer per requested product, in the order of the request; a repeated id answers once.',
                content: new OA\JsonContent(ref: new Model(type: ProductOfferListResponse::class)),
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
        $query = new ProductOffersQuery(self::productIds($request));
        $violations = $this->validator->validate($query);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($query, $violations);
        }

        /** @var list<ProductOfferView> $offers */
        $offers = $this->handle(new GetProductOffers($query->productId));

        $response = new JsonResponse(new ProductOfferListResponse(array_map(
            static fn (ProductOfferView $offer): ProductOfferResponse => ProductOfferResponse::of($offer),
            $offers,
        )));
        $response->setPublic();
        $response->setMaxAge(60);
        $this->cacheTagger->tag($response, [
            CacheTags::CATALOG,
            ...array_map(CacheTags::product(...), $query->productId),
        ]);

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
            if (\count($ids) > GetProductOffers::MAX_PRODUCTS) {
                break;
            }
        }

        return $ids;
    }
}
