<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\InvalidSitemapChunk;
use App\Catalog\Application\Query\ListSitemapProducts;
use App\Catalog\Application\Query\SitemapProductList;
use App\Shared\Infrastructure\Http\Cache\PublicReadErrorCacheSubscriber;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class SitemapProductsController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/sitemap/products', name: 'catalog_sitemap_products', methods: ['GET'], defaults: [PublicReadErrorCacheSubscriber::ROUTE_DEFAULT => true])]
    #[OA\Get(
        operationId: 'getSitemapProducts',
        summary: 'Products of one chunk of the product sitemap',
        description: 'The published products of the chunk, in the order of the product id, with the slug and the time of the last '
            .'change of the product (prices and stock levels do not change it). A chunk without published products has an empty '
            .'list. The number of rows read from the range of the chunk is bounded, drafts included: past the limit the published ones '
            .'among the rows read are returned, and the rest comes with the next cut of the chunks. The response is public and kept '
            .'by a browser for 5 minutes; it is not tagged and the Varnish of the store does not keep it, so its freshness is the '
            .'short `max-age`.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'chunk', in: 'query', required: true, description: 'Key of the chunk, as the chunk list issued it.', schema: new OA\Schema(type: 'string', maxLength: 200)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The published products of the chunk.',
                content: new OA\JsonContent(ref: new Model(type: SitemapProductList::class)),
            ),
            new OA\Response(
                response: 400,
                description: 'The `chunk` parameter is missing or is not a key that the chunk list issued (problem details with `violations`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
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
    public function __invoke(Request $request): JsonResponse
    {
        // `all()`, not `get()`: a repeated or bracketed key (`chunk[]=…`) must be a violation, not an exception.
        $chunk = $request->query->all()['chunk'] ?? null;
        if (null === $chunk || '' === $chunk) {
            throw $this->violation($chunk, 'Klucz porcji jest wymagany.');
        }
        if (!\is_string($chunk)) {
            throw $this->violation(null, 'Klucz porcji jest niepoprawny.');
        }

        try {
            /** @var SitemapProductList $products */
            $products = $this->handle(new ListSitemapProducts($chunk));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a key nobody issued is a bad parameter, a 400.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof InvalidSitemapChunk) {
                throw $this->violation(null, 'Klucz porcji jest niepoprawny.');
            }

            throw $failure;
        }

        // Not tagged: a chunk would need a tag per product, and the short lifetime keeps it fresh enough.
        $response = new JsonResponse($products);
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    private function violation(mixed $value, string $message): ValidationFailedException
    {
        return new ValidationFailedException($value, new ConstraintViolationList([new ConstraintViolation($message, null, [], $value, 'chunk', $value)]));
    }
}
