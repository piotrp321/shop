<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Customer\Application\Security\AuthenticatedCustomer;
use App\Ordering\Application\CartReference;
use App\Shared\Infrastructure\Http\RateLimit;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The customer of the token (`sub` of the `shop` realm) places an order of the cart of the customer; the email is the
 * one of the token, never of the body. Every response is `private, no-store` (OrderResponseCacheSubscriber).
 */
#[Security(name: 'shop')]
final readonly class CustomerOrderController
{
    public function __construct(private OrderPlacement $placement)
    {
    }

    #[Route('/api/v1/me/orders', name: 'ordering_customer_place', methods: ['POST'], defaults: [RateLimit::ROUTE_DEFAULT => RateLimit::ORDER_PLACEMENT, RateLimit::KEY_ROUTE_DEFAULT => RateLimit::BY_CUSTOMER])]
    #[OA\Post(
        operationId: 'placeCustomerOrder',
        summary: 'Place an order of the cart of the customer',
        description: 'Orders every line of the cart of the customer in one transaction: the quantities come from the cart, the prices from '
            .'Pricing and the names from Catalog at this moment, the units are reserved and the cart is emptied. The email is the one of the '
            .'token (an `email` key is ignored); the body carries no lines, quantities or prices, unknown keys are ignored. `expectedTotalGross` '
            .'is only compared with the computed gross total. The optional `discountCode` (the code of the checkout) discounts the order: its gross total is after the discount and the usage of the code is recorded with the order (once, also on a replay). '
            .'The `Idempotency-Key` identifies the request: sending it again with the same '
            .'content answers 200 with the same order and changes nothing; the same key used by another customer, a guest or with other '
            .'content is a 409 that tells nothing about the order.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Key of the request, chosen by the client (e.g. a UUID made when the checkout opens) and sent again on a retry: 1 to 100 characters, letters, digits, ".", "_", ":" or "-".', schema: new OA\Schema(type: 'string', maxLength: 100, minLength: 1, pattern: '^[A-Za-z0-9._:-]{1,100}$')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'The order placed.', content: new OA\JsonContent(ref: '#/components/schemas/OrderView')),
            new OA\Response(response: 200, description: 'The order of an earlier request of the customer with this key and the same content: nothing changed.', content: new OA\JsonContent(ref: '#/components/schemas/OrderView')),
            new OA\Response(response: 400, description: 'The `Idempotency-Key` header is missing or malformed (problem details with `violations`), or the body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The cart is empty (also without a cart or with an expired one), has a line that cannot be bought (no current price, unknown variant), more units than available (never told how many), a gross total other than `expectedTotalGross` (after the discount of the code), a discount code that gives no discount (invalid, used up, or the subtotal below its minimum), or the key was used by another request. Problem details with a stable `type` for each cause (see `OrderConflictProblem`) and a Polish `detail`. Nothing is ordered or reserved.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/OrderConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'A key is missing, of a wrong type or invalid (problem details with Polish `violations`, one per field).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 429, description: 'Too many orders of the customer (by the account, whatever the address): the rate limit of the order placement. Nothing is ordered or reserved, so a retry with the same `Idempotency-Key` is safe. Problem details with a Polish `detail`; `Retry-After` tells the seconds to wait.', headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before the next attempt.', schema: new OA\Schema(type: 'integer', minimum: 1))], content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] AuthenticatedCustomer $customer,
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY, mapWhenEmpty: true)]
        PlaceCustomerOrderRequest $order,
    ): JsonResponse {
        return $this->placement->place($request, CartReference::customer($customer->id()), $customer->email(), $order);
    }
}
