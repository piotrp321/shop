<?php

declare(strict_types=1);

namespace App\Payment\UI\Http;

use App\Customer\Application\Security\AuthenticatedCustomer;
use App\Payment\Application\PaymentAccess;
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
 * The customer of the token (`sub` of the `shop` realm) reaches the payment of own orders only; an order of somebody
 * else or of a guest is not found. Every response is `private, no-store` (PaymentResponseCacheSubscriber).
 */
#[Security(name: 'shop')]
final readonly class CustomerPaymentController
{
    public function __construct(private PaymentEndpoints $endpoints)
    {
    }

    #[Route('/api/v1/me/orders/{orderId}/payments', name: 'payment_customer_start', methods: ['POST'], defaults: [RateLimit::ROUTE_DEFAULT => RateLimit::PAYMENT_START, RateLimit::KEY_ROUTE_DEFAULT => RateLimit::BY_CUSTOMER])]
    #[OA\Post(
        operationId: 'startCustomerPayment',
        summary: 'Start a payment of an order of the customer',
        description: 'As `startGuestPayment`, for an order of the customer of the token.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Id of the order.', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Key of the request, chosen by the client (new for every attempt and every change of provider, the same on a retry of an uncertain result): 1 to 100 characters, letters, digits, ".", "_", ":" or "-".', schema: new OA\Schema(type: 'string', maxLength: 100, minLength: 1, pattern: '^[A-Za-z0-9._:-]{1,100}$')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'The payment started.', content: new OA\JsonContent(ref: '#/components/schemas/PaymentView')),
            new OA\Response(response: 200, description: 'The payment of an earlier request with this key for the same order and provider, as it is now.', content: new OA\JsonContent(ref: '#/components/schemas/PaymentView')),
            new OA\Response(response: 400, description: 'The `Idempotency-Key` header is missing or malformed (problem details with `violations`), or the body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such order of the customer: unknown, an id that is no UUID, an order of another customer or of a guest (problem details, one text for all).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The order is paid, cancelled or past its deadline, another payment is in progress, or the key was used by another request. Problem details with a stable `type` (see `PaymentConflictProblem`) and a Polish `detail`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/PaymentConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: '`provider` is missing, empty or not one of the providers (problem details with a Polish violation).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 429, description: 'Too many payment starts of the customer (by the account, whatever the address): the rate limit of the payment start. Nothing is started, so a retry with the same `Idempotency-Key` is safe. Problem details with a Polish `detail`; `Retry-After` tells the seconds to wait.', headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before the next attempt.', schema: new OA\Schema(type: 'integer', minimum: 1))], content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The provider did not answer properly in 5 s, nothing changed; or the keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function start(
        Request $request,
        string $orderId,
        #[CurrentUser] AuthenticatedCustomer $customer,
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY, mapWhenEmpty: true)]
        StartPaymentRequest $payment,
    ): JsonResponse {
        return $this->endpoints->start($request, $orderId, PaymentAccess::customer($customer->id()), $payment);
    }

    #[Route('/api/v1/me/orders/{orderId}/payment', name: 'payment_customer_status', methods: ['GET'])]
    #[OA\Get(
        operationId: 'getCustomerPaymentStatus',
        summary: 'Status of the payment of an order of the customer',
        description: 'As `getGuestPaymentStatus`, for an order of the customer of the token.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Id of the order.', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The status.', content: new OA\JsonContent(ref: '#/components/schemas/PaymentStatusView')),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such order of the customer (problem details, one text for every case).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function status(string $orderId, #[CurrentUser] AuthenticatedCustomer $customer): JsonResponse
    {
        return $this->endpoints->status($orderId, PaymentAccess::customer($customer->id()));
    }
}
