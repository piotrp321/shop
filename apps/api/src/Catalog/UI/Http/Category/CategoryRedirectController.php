<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use App\Catalog\Application\Query\GetCategoryRedirect;
use App\Catalog\Domain\Category\CategorySlugPath;
use App\Shared\Infrastructure\Http\Cache\CacheTagger;
use App\Shared\Infrastructure\Http\Cache\CacheTags;
use App\Shared\Infrastructure\Http\Cache\PublicReadErrorCacheSubscriber;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Where an old address of a category leads (spec ADMIN-FEATURES, 4 "Przekierowania"): the store asks for an address
 * outside the tree and redirects to the answer. Public and cached by Varnish under the tag `catalog`, so a change of
 * the tree (CategoryTreeChanged) refreshes it.
 */
final class CategoryRedirectController
{
    use HandleTrait;

    private const string INVALID = 'Adres kategorii to od 1 do 4 slugów rozdzielonych ukośnikiem.';

    public function __construct(MessageBusInterface $queryBus, private readonly CacheTagger $cacheTagger)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/categories/redirect', name: 'catalog_category_redirect', methods: ['GET'], defaults: [PublicReadErrorCacheSubscriber::ROUTE_DEFAULT => true])]
    #[OA\Get(
        operationId: 'getCategoryRedirect',
        summary: 'Current address of an old category address',
        description: 'An address a category or one of its ancestors had before a change of a slug or a move leads to the current address of '
            .'the category, also after further changes. The current address of a category, an address of a deleted category and an unknown '
            .'address are 404. Both answers may be kept by a shared cache for 60 seconds.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'path', in: 'query', required: true, description: 'The old address: 1 to 4 category slugs joined with `/`, e.g. `turystyka/namioty`.', schema: new OA\Schema(type: 'string', maxLength: 243, example: 'turystyka/namioty')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The current address.', content: new OA\JsonContent(ref: new Model(type: CategoryRedirectResponse::class))),
            new OA\Response(response: 400, description: 'The `path` parameter is missing or not 1 to 4 slugs joined with `/` (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'The address leads to no category (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        // `all()`, not `get()`: a bracketed key (`path[]=…`) must be a violation, not an exception.
        $path = $request->query->all()['path'] ?? null;
        try {
            $slugPath = \is_string($path) ? CategorySlugPath::fromString($path) : throw new \InvalidArgumentException('The path is not a text.');
        } catch (\InvalidArgumentException) {
            throw new ValidationFailedException($path, new ConstraintViolationList([new ConstraintViolation(self::INVALID, null, [], $path, 'path', \is_string($path) ? $path : null)]));
        }

        /** @var string|null $current */
        $current = $this->handle(new GetCategoryRedirect($slugPath));

        // Built here, not thrown, so the 404 carries the cache headers too: Varnish keeps it for 60 seconds under `catalog`.
        $response = null === $current
            ? new JsonResponse(['type' => 'about:blank', 'title' => Response::$statusTexts[Response::HTTP_NOT_FOUND], 'status' => Response::HTTP_NOT_FOUND, 'detail' => 'Nie ma przekierowania dla tego adresu.'], Response::HTTP_NOT_FOUND, ['Content-Type' => 'application/problem+json'])
            : new JsonResponse(new CategoryRedirectResponse($current));
        $response->setPublic();
        $response->setMaxAge(60);
        $this->cacheTagger->tag($response, [CacheTags::CATALOG]);

        return $response;
    }
}
