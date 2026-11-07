<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\GetProductBySlug;
use App\Catalog\Application\Query\ProductDetails;
use App\Shared\Infrastructure\Http\Cache\CacheTagger;
use App\Shared\Infrastructure\Http\Cache\CacheTags;
use App\Shared\Infrastructure\Http\Cache\PublicReadErrorCacheSubscriber;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ProductDetailsController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus, private readonly CacheTagger $cacheTagger)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/products/{slug}', name: 'catalog_product_details', methods: ['GET'], defaults: [PublicReadErrorCacheSubscriber::ROUTE_DEFAULT => true])]
    #[OA\Get(
        operationId: 'getProduct',
        summary: 'Product details',
        description: 'The product page data: the category path from the root to the main category, all the images in position order, '
            .'the variant axes and the variants (label and values in the order of the axes) in position order. The price and the '
            .'availability are in the product offer. A slug that names no published product (a draft, an unknown slug, one that is not in '
            .'the format of a slug) gives 404. The 200 may be kept by a shared cache for an hour and is tagged for purging; the 404 for '
            .'60 seconds, tagged for purging when the product is published.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, description: 'Slug of the product.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The product.',
                content: new OA\JsonContent(ref: '#/components/schemas/ProductDetails'),
            ),
            new OA\Response(
                response: 401,
                description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 404,
                description: 'No published product has the slug (problem details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without technical details).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    public function __invoke(string $slug): JsonResponse
    {
        /** @var ProductDetails|null $details */
        $details = $this->handle(new GetProductBySlug($slug));

        if (null === $details) {
            // Built here, not thrown, so the answer carries the cache headers: a missing product is cached by Varnish
            // for 60 seconds under the `catalog` tag and, for a slug that a product can have, the tag of the slug, so
            // the publication of the product drops it (decision A4).
            $response = new JsonResponse(
                ['type' => 'about:blank', 'title' => Response::$statusTexts[Response::HTTP_NOT_FOUND], 'status' => Response::HTTP_NOT_FOUND, 'detail' => 'Produkt nie istnieje.'],
                Response::HTTP_NOT_FOUND,
            );
            $response->headers->set('Content-Type', 'application/problem+json');
            $response->setPublic();
            $response->setMaxAge(60);
            $this->cacheTagger->tag($response, self::notFoundTags($slug));

            return $response;
        }

        $response = new JsonResponse($details);
        $response->setPublic();
        $response->setMaxAge(60);
        $response->setSharedMaxAge(3600);
        $this->cacheTagger->tag($response, [CacheTags::CATALOG, CacheTags::product($details->id)]);

        return $response;
    }

    /**
     * @return list<string>
     */
    private static function notFoundTags(string $slug): array
    {
        try {
            return [CacheTags::CATALOG, CacheTags::productSlug($slug)];
        } catch (\InvalidArgumentException) {
            // Not the format of a product slug: no product can ever have it.
            return [CacheTags::CATALOG];
        }
    }
}
