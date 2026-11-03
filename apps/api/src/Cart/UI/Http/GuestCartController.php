<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Application\CartRef;
use App\Cart\Application\Command\AddCartItem;
use App\Cart\Application\Command\ChangeCartItemQuantity;
use App\Cart\Application\Command\CreateGuestCart;
use App\Cart\Application\Command\RemoveCartItem;
use App\Cart\Application\Query\CartSummary;
use App\Cart\Application\Query\GetCart;
use App\Cart\Application\Query\GetCartSummary;
use App\Cart\Application\View\CartView;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * The cart of a guest, without a token: the cart id is the key to it. An unknown, expired or merged cart is a 404
 * like an id that is not a UUID. Every response is `private, no-store` (CartResponseCacheSubscriber).
 */
final readonly class GuestCartController
{
    /** A UUID in any letter case; anything else matches no route (404), like an unknown cart. */
    public const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        #[Autowire(service: 'query.bus')] private MessageBusInterface $queryBus,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/api/v1/guest-carts', name: 'cart_guest_create', methods: ['POST'])]
    #[OA\Post(
        operationId: 'createGuestCart',
        summary: 'Create a guest cart',
        description: 'A new, empty cart with a random id, which is also the key to it. It expires 30 days after its last change. No body is read.',
        tags: ['Cart'],
        responses: [
            new OA\Response(response: 201, description: 'The empty cart; `Location` is its address.', headers: [new OA\Header(header: 'Location', description: 'Address of the cart.', schema: new OA\Schema(type: 'string'))], content: new OA\JsonContent(ref: '#/components/schemas/CartView')),
            new OA\Response(response: 401, description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function create(): JsonResponse
    {
        $view = $this->dispatch($this->commandBus, new CreateGuestCart());
        \assert($view instanceof CartView && null !== $view->id);

        return new JsonResponse($view, Response::HTTP_CREATED, [
            'Location' => $this->urls->generate('cart_guest_get', ['cartId' => $view->id]),
        ]);
    }

    #[Route('/api/v1/guest-carts/{cartId}', name: 'cart_guest_get', requirements: ['cartId' => self::UUID], methods: ['GET'])]
    #[OA\Get(
        operationId: 'getGuestCart',
        summary: 'Guest cart',
        description: 'The cart with its lines, their states and the totals, all computed by the API. Nothing is changed.',
        tags: ['Cart'],
        parameters: [new OA\Parameter(name: 'cartId', in: 'path', required: true, description: 'Cart id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The cart.', content: new OA\JsonContent(ref: '#/components/schemas/CartView')),
            new OA\Response(response: 401, description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such cart: unknown, expired or merged, or the id is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function get(string $cartId): JsonResponse
    {
        return new JsonResponse($this->dispatch($this->queryBus, new GetCart(CartRef::guest($cartId))));
    }

    #[Route('/api/v1/guest-carts/{cartId}/summary', name: 'cart_guest_summary', requirements: ['cartId' => self::UUID], methods: ['GET'])]
    #[OA\Get(
        operationId: 'getGuestCartSummary',
        summary: 'Number of units in a guest cart',
        description: 'The counter of the header: the units of every line, in one read and without asking other modules.',
        tags: ['Cart'],
        parameters: [new OA\Parameter(name: 'cartId', in: 'path', required: true, description: 'Cart id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The number of units.', content: new OA\JsonContent(ref: '#/components/schemas/CartSummary')),
            new OA\Response(response: 401, description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such cart: unknown, expired or merged, or the id is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function summary(string $cartId): JsonResponse
    {
        $summary = $this->dispatch($this->queryBus, new GetCartSummary(CartRef::guest($cartId)));
        \assert($summary instanceof CartSummary);

        return new JsonResponse($summary);
    }

    #[Route('/api/v1/guest-carts/{cartId}/items', name: 'cart_guest_add_item', requirements: ['cartId' => self::UUID], methods: ['POST'])]
    #[OA\Post(
        operationId: 'addGuestCartItem',
        summary: 'Add a product to a guest cart',
        description: 'Adds a line, or adds the units to the line of the product. The product must have a current price and enough available '
            .'units (the number of units is never told); the cart holds at most 50 products and 99 units of one. Nothing is reserved.',
        tags: ['Cart'],
        parameters: [new OA\Parameter(name: 'cartId', in: 'path', required: true, description: 'Cart id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The cart after the change.', content: new OA\JsonContent(ref: '#/components/schemas/CartView')),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such cart (unknown, expired or merged, or the id is not a UUID) or no such product in Catalog (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'More than 99 units of the product, a 51st product, a product without a current price or available units, or more units than available (problem details with a Polish `detail`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A key is missing, of a wrong type, the quantity is out of 1–99 or the product id is not a UUID (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function addItem(
        string $cartId,
        #[MapRequestPayload(acceptFormat: 'json', serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true], validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY, mapWhenEmpty: true)]
        AddCartItemRequest $item,
    ): JsonResponse {
        // Validated by the payload mapping: a UUID string and an integer.
        \assert(\is_string($item->productId) && \is_int($item->quantity));

        return new JsonResponse($this->dispatch($this->commandBus, new AddCartItem(CartRef::guest($cartId), $item->productId, $item->quantity)));
    }

    #[Route('/api/v1/guest-carts/{cartId}/items/{productId}', name: 'cart_guest_change_item', requirements: ['cartId' => self::UUID, 'productId' => self::UUID], methods: ['PUT'])]
    #[OA\Put(
        operationId: 'changeGuestCartItemQuantity',
        summary: 'Change the quantity of a line of a guest cart',
        description: 'Sets the quantity. A decrease and the same quantity are always possible; an increase needs a current price and enough available units, like an addition.',
        tags: ['Cart'],
        parameters: [
            new OA\Parameter(name: 'cartId', in: 'path', required: true, description: 'Cart id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'productId', in: 'path', required: true, description: 'Product id of the line (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The cart after the change.', content: new OA\JsonContent(ref: '#/components/schemas/CartView')),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such cart (unknown, expired or merged, or an id is not a UUID), no such line, or an increase of a product Catalog no longer knows (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'An increase of a product without a current price or available units, or above the available units (problem details with a Polish `detail`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'The quantity is missing, not an integer or out of 1–99 (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function changeItem(
        string $cartId,
        string $productId,
        #[MapRequestPayload(acceptFormat: 'json', serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true], validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY, mapWhenEmpty: true)]
        ChangeCartItemQuantityRequest $change,
    ): JsonResponse {
        \assert(\is_int($change->quantity));

        return new JsonResponse($this->dispatch($this->commandBus, new ChangeCartItemQuantity(CartRef::guest($cartId), $productId, $change->quantity)));
    }

    #[Route('/api/v1/guest-carts/{cartId}/items/{productId}', name: 'cart_guest_remove_item', requirements: ['cartId' => self::UUID, 'productId' => self::UUID], methods: ['DELETE'])]
    #[OA\Delete(
        operationId: 'removeGuestCartItem',
        summary: 'Remove a line of a guest cart',
        description: 'Removes the line of the product; a cart without it is answered as it is.',
        tags: ['Cart'],
        parameters: [
            new OA\Parameter(name: 'cartId', in: 'path', required: true, description: 'Cart id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'productId', in: 'path', required: true, description: 'Product id of the line (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The cart after the change.', content: new OA\JsonContent(ref: '#/components/schemas/CartView')),
            new OA\Response(response: 401, description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such cart: unknown, expired or merged, or an id is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function removeItem(string $cartId, string $productId): JsonResponse
    {
        return new JsonResponse($this->dispatch($this->commandBus, new RemoveCartItem(CartRef::guest($cartId), $productId)));
    }

    /**
     * What the handler returned; a failure of the handler passes on wrapped (ProblemDetailsSubscriber unwraps it).
     */
    private function dispatch(MessageBusInterface $bus, object $message): mixed
    {
        return $bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
