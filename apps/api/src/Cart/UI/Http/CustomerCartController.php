<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Application\CartRef;
use App\Cart\Application\Command\AddCartItem;
use App\Cart\Application\Command\ChangeCartItemQuantity;
use App\Cart\Application\Command\MergeGuestCart;
use App\Cart\Application\Command\RemoveCartItem;
use App\Cart\Application\Query\CartSummary;
use App\Cart\Application\Query\GetCart;
use App\Cart\Application\Query\GetCartSummary;
use App\Customer\Application\Security\AuthenticatedCustomer;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * The cart of the customer of the token (`sub` of the `shop` realm); its id is never in the path. The first change
 * or merge creates it; reads never do. Every response is `private, no-store` (CartResponseCacheSubscriber).
 */
#[Security(name: 'shop')]
final readonly class CustomerCartController
{
    public function __construct(
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        #[Autowire(service: 'query.bus')] private MessageBusInterface $queryBus,
    ) {
    }

    #[Route('/api/v1/me/cart', name: 'cart_customer_get', methods: ['GET'])]
    #[OA\Get(
        operationId: 'getCustomerCart',
        summary: 'Cart of the customer',
        description: 'The cart with its lines, their states and the totals, all computed by the API. A customer without a cart gets an '
            .'empty one with `id: null`, and none is created; an expired cart (180 days after its last change) is empty.',
        tags: ['Cart'],
        responses: [
            new OA\Response(response: 200, description: 'The cart.', content: new OA\JsonContent(ref: '#/components/schemas/CartView')),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function get(#[CurrentUser] AuthenticatedCustomer $customer): JsonResponse
    {
        return new JsonResponse($this->dispatch($this->queryBus, new GetCart(CartRef::customer($customer->id()))));
    }

    #[Route('/api/v1/me/cart/summary', name: 'cart_customer_summary', methods: ['GET'])]
    #[OA\Get(
        operationId: 'getCustomerCartSummary',
        summary: 'Number of units in the cart of the customer',
        description: 'The counter of the header: the units of every line, in one read and without asking other modules; 0 without a cart.',
        tags: ['Cart'],
        responses: [
            new OA\Response(response: 200, description: 'The number of units.', content: new OA\JsonContent(ref: '#/components/schemas/CartSummary')),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function summary(#[CurrentUser] AuthenticatedCustomer $customer): JsonResponse
    {
        $summary = $this->dispatch($this->queryBus, new GetCartSummary(CartRef::customer($customer->id())));
        \assert($summary instanceof CartSummary);

        return new JsonResponse($summary);
    }

    #[Route('/api/v1/me/cart/items', name: 'cart_customer_add_item', methods: ['POST'])]
    #[OA\Post(
        operationId: 'addCustomerCartItem',
        summary: 'Add a product to the cart of the customer',
        description: 'Adds a line, or adds the units to the line of the product; the first change creates the cart. The product must '
            .'have a current price and enough available units (the number of units is never told); the cart holds at most 50 products '
            .'and 99 units of one. Nothing is reserved.',
        tags: ['Cart'],
        responses: [
            new OA\Response(response: 200, description: 'The cart after the change.', content: new OA\JsonContent(ref: '#/components/schemas/CartView')),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such product in Catalog (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'More than 99 units of the product, a 51st product, a product without a current price or available units, or more units than available (problem details with a Polish `detail`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A key is missing, of a wrong type, the quantity is out of 1–99 or the product id is not a UUID (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function addItem(
        #[CurrentUser] AuthenticatedCustomer $customer,
        #[MapRequestPayload(acceptFormat: 'json', serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true], validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY, mapWhenEmpty: true)]
        AddCartItemRequest $item,
    ): JsonResponse {
        // Validated by the payload mapping: a UUID string and an integer.
        \assert(\is_string($item->productId) && \is_int($item->quantity));

        return new JsonResponse($this->dispatch($this->commandBus, new AddCartItem(CartRef::customer($customer->id()), $item->productId, $item->quantity)));
    }

    #[Route('/api/v1/me/cart/items/{productId}', name: 'cart_customer_change_item', requirements: ['productId' => GuestCartController::UUID], methods: ['PUT'])]
    #[OA\Put(
        operationId: 'changeCustomerCartItemQuantity',
        summary: 'Change the quantity of a line of the cart of the customer',
        description: 'Sets the quantity. A decrease and the same quantity are always possible; an increase needs a current price and enough available units, like an addition.',
        tags: ['Cart'],
        parameters: [new OA\Parameter(name: 'productId', in: 'path', required: true, description: 'Product id of the line (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The cart after the change.', content: new OA\JsonContent(ref: '#/components/schemas/CartView')),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such line, an id that is not a UUID, or an increase of a product Catalog no longer knows (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'An increase of a product without a current price or available units, or above the available units (problem details with a Polish `detail`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'The quantity is missing, not an integer or out of 1–99 (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function changeItem(
        #[CurrentUser] AuthenticatedCustomer $customer,
        string $productId,
        #[MapRequestPayload(acceptFormat: 'json', serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true], validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY, mapWhenEmpty: true)]
        ChangeCartItemQuantityRequest $change,
    ): JsonResponse {
        \assert(\is_int($change->quantity));

        return new JsonResponse($this->dispatch($this->commandBus, new ChangeCartItemQuantity(CartRef::customer($customer->id()), $productId, $change->quantity)));
    }

    #[Route('/api/v1/me/cart/items/{productId}', name: 'cart_customer_remove_item', requirements: ['productId' => GuestCartController::UUID], methods: ['DELETE'])]
    #[OA\Delete(
        operationId: 'removeCustomerCartItem',
        summary: 'Remove a line of the cart of the customer',
        description: 'Removes the line of the product; a cart without it is answered as it is, a customer without a cart gets the empty one and none is created.',
        tags: ['Cart'],
        parameters: [new OA\Parameter(name: 'productId', in: 'path', required: true, description: 'Product id of the line (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The cart after the change.', content: new OA\JsonContent(ref: '#/components/schemas/CartView')),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'The product id is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function removeItem(#[CurrentUser] AuthenticatedCustomer $customer, string $productId): JsonResponse
    {
        return new JsonResponse($this->dispatch($this->commandBus, new RemoveCartItem(CartRef::customer($customer->id()), $productId)));
    }

    #[Route('/api/v1/me/cart/merge', name: 'cart_customer_merge', methods: ['POST'])]
    #[OA\Post(
        operationId: 'mergeGuestCart',
        summary: 'Merge a guest cart into the cart of the customer',
        description: 'Moves the lines of the guest cart into the cart of the customer, in one transaction: the quantities of a product in both '
            .'are added and cut to 99, a product of the guest beyond the 50th of the cart is skipped (either makes `truncated` true); price and '
            .'availability are not checked. The guest cart is then merged and every later change of it is a 404. An expired cart of the customer '
            .'is cleared first. A merged guest cart gives `already_merged`; an unknown or expired guest cart, or the id of a customer cart, '
            .'gives `nothing_to_merge`; neither changes anything nor creates a cart, and `cart` is the cart as `GET /api/v1/me/cart` gives it.',
        tags: ['Cart'],
        responses: [
            new OA\Response(response: 200, description: 'The cart of the customer and how the merge ended.', content: new OA\JsonContent(ref: '#/components/schemas/MergeResult')),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'The guest cart id is missing, not a string or not a UUID (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function merge(
        #[CurrentUser] AuthenticatedCustomer $customer,
        #[MapRequestPayload(acceptFormat: 'json', serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true], validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY, mapWhenEmpty: true)]
        MergeGuestCartRequest $merge,
    ): JsonResponse {
        // Validated by the payload mapping: a UUID string.
        \assert(\is_string($merge->guestCartId));

        return new JsonResponse($this->dispatch($this->commandBus, new MergeGuestCart($customer->id(), $merge->guestCartId)));
    }

    /**
     * What the handler returned; a failure of the handler passes on wrapped (ProblemDetailsSubscriber unwraps it).
     */
    private function dispatch(MessageBusInterface $bus, object $message): mixed
    {
        return $bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
