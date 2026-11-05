<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\CartReference;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A guest places an order of the guest cart named in the body, without a token: the random cart id (from the signed
 * cookie of the store) is the key to the cart. A valid token changes nothing here; a customer uses /api/v1/me/orders.
 * Every response is `private, no-store` (OrderResponseCacheSubscriber).
 */
final readonly class GuestOrderController
{
    public function __construct(private OrderPlacement $placement)
    {
    }

    #[Route('/api/v1/orders', name: 'ordering_guest_place', methods: ['POST'])]
    #[OA\Post(
        operationId: 'placeGuestOrder',
        summary: 'Place an order of a guest cart',
        description: 'Orders every line of the guest cart in one transaction: the quantities come from the cart, the prices from Pricing and '
            .'the names from Catalog at this moment, the units are reserved and the cart is emptied (it stays open). The body carries no lines, '
            .'quantities or prices; unknown keys are ignored. `expectedTotalGross` is only compared with the computed gross total. '
            .'The `Idempotency-Key` identifies the request: sending it again with the same cart and content answers 200 with the same order and '
            .'changes nothing, also once the cart expired or was merged; the same key with another cart or other content is a 409 that tells '
            .'nothing about the order. The order cannot be read later, so there is no `Location`.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Key of the request, chosen by the client (e.g. a UUID made when the checkout opens) and sent again on a retry: 1 to 100 characters, letters, digits, ".", "_", ":" or "-".', schema: new OA\Schema(type: 'string', maxLength: 100, minLength: 1, pattern: '^[A-Za-z0-9._:-]{1,100}$')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'The order placed.', content: new OA\JsonContent(ref: '#/components/schemas/OrderView')),
            new OA\Response(response: 200, description: 'The order of an earlier request with this key, the same cart and the same content: nothing changed.', content: new OA\JsonContent(ref: '#/components/schemas/OrderView')),
            new OA\Response(response: 400, description: 'The `Idempotency-Key` header is missing or malformed (problem details with `violations`), or the body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'An access token was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). No token is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such guest cart: unknown, expired or merged, or the id of a customer cart (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The cart is empty, has a line that cannot be bought (no current price, unknown variant), more units than available (never told how many), a gross total other than `expectedTotalGross`, or the key was used by another request. Problem details with a stable `type` for each cause (see `OrderConflictProblem`) and a Polish `detail`. Nothing is ordered or reserved.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/OrderConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A key is missing, of a wrong type or invalid (problem details with Polish `violations`, one per field).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'A token was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function __invoke(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY, mapWhenEmpty: true)]
        PlaceGuestOrderRequest $order,
    ): JsonResponse {
        // Validated by the payload mapping: a UUID string and a string.
        \assert(\is_string($order->cartId) && \is_string($order->email));

        return $this->placement->place($request, CartReference::guest($order->cartId), $order->email, $order);
    }
}
