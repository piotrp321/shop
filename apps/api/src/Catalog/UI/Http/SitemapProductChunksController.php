<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ListSitemapProductChunks;
use App\Catalog\Application\Query\SitemapChunkList;
use App\Shared\Infrastructure\Http\Cache\CacheTagger;
use App\Shared\Infrastructure\Http\Cache\CacheTags;
use App\Shared\Infrastructure\Http\Cache\PublicReadErrorCacheSubscriber;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapProductChunksController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus, private readonly CacheTagger $cacheTagger)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/sitemap/product-chunks', name: 'catalog_sitemap_product_chunks', methods: ['GET'], defaults: [PublicReadErrorCacheSubscriber::ROUTE_DEFAULT => true])]
    #[OA\Get(
        operationId: 'listSitemapProductChunks',
        summary: 'Chunks of the product sitemap',
        description: 'The chunks that the product sitemap is cut into, in the order of the product id. Every product, published or '
            .'not, belongs to exactly one chunk; the first chunk reaches back to the lowest id and the last to the highest, so a '
            .'product created after the chunks were cut is in one of them. The key of a chunk is opaque: pass it unchanged to '
            .'`getSitemapProducts`. An empty catalogue has one chunk. The chunks are cut from the ids of all the products and the cut '
            .'is kept for an hour, so a new product can move the cut only after that. The response is public, kept for 60 s by '
            .'a browser and for an hour by a shared cache, and tagged for purging.',
        tags: ['Catalog'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The chunks, at least one.',
                content: new OA\JsonContent(ref: new Model(type: SitemapChunkList::class)),
            ),
            new OA\Response(
                response: 401,
                description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        /** @var SitemapChunkList $chunks */
        $chunks = $this->handle(new ListSitemapProductChunks());

        $response = new JsonResponse($chunks);
        $response->setPublic();
        $response->setMaxAge(60);
        $response->setSharedMaxAge(3600);
        $this->cacheTagger->tag($response, [CacheTags::CATALOG]);

        return $response;
    }
}
