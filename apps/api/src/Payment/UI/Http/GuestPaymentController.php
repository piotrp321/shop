<?php

declare(strict_types=1);

namespace App\Payment\UI\Http;

use App\Payment\Application\OrderNotFound;
use App\Payment\Application\PaymentAccess;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A guest reaches the payment of an order by the access token given when the order was placed (header
 * `Order-Access-Token`), without a token of a session. Without the header, with another token or for an order of a
 * customer the order is not found. Every response is `private, no-store` (PaymentResponseCacheSubscriber).
 */
final readonly class GuestPaymentController
{
    private const string ACCESS_TOKEN = 'Order-Access-Token';

    public function __construct(private PaymentEndpoints $endpoints)
    {
    }

    #[Route('/api/v1/orders/{orderId}/payments', name: 'payment_guest_start', methods: ['POST'])]
    #[OA\Post(
        operationId: 'startGuestPayment',
        summary: 'Start a payment of an order of a guest',
        description: 'Opens a session at the chosen provider for the gross total of the order, valid until its payment deadline, and '
            .'answers where to send the customer (`redirectUrl`). A pending payment of the order is cancelled at its provider first; when '
            .'that provider executed it already, nothing changes (409 `payment-in-progress`). The `Idempotency-Key` identifies the request: '
            .'the same key for the same order and provider answers 200 with that payment as it is now; with another order or provider it is '
            .'a 409. The access is checked before the key, so the key alone never gives a payment.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Id of the order (`orderId` of the placed order).', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Order-Access-Token', in: 'header', required: true, description: 'The `accessToken` of the placed order.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Key of the request, chosen by the client (new for every attempt and every change of provider, the same on a retry of an uncertain result): 1 to 100 characters, letters, digits, ".", "_", ":" or "-".', schema: new OA\Schema(type: 'string', maxLength: 100, minLength: 1, pattern: '^[A-Za-z0-9._:-]{1,100}$')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'The payment started.', content: new OA\JsonContent(ref: '#/components/schemas/PaymentView')),
            new OA\Response(response: 200, description: 'The payment of an earlier request with this key for the same order and provider, as it is now.', content: new OA\JsonContent(ref: '#/components/schemas/PaymentView')),
            new OA\Response(response: 400, description: 'The `Idempotency-Key` header is missing or malformed (problem details with `violations`), or the body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'An access token of a session was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). None is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such order for this token: unknown, an id that is no UUID, a missing or wrong `Order-Access-Token`, or an order of a customer (problem details, one text for all).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The order is paid, cancelled or past its deadline, another payment is in progress (a concurrent start, or the pending one was executed at its provider), or the key was used by another request. Problem details with a stable `type` (see `PaymentConflictProblem`) and a Polish `detail`.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/PaymentConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: '`provider` is missing, empty or not one of the providers (problem details with a Polish violation).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The provider did not answer properly in 5 s, nothing changed; or a token of a session was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function start(
        Request $request,
        string $orderId,
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY, mapWhenEmpty: true)]
        StartPaymentRequest $payment,
    ): JsonResponse {
        return $this->endpoints->start($request, $orderId, self::access($request), $payment);
    }

    #[Route('/api/v1/orders/{orderId}/payment', name: 'payment_guest_status', methods: ['GET'])]
    #[OA\Get(
        operationId: 'getGuestPaymentStatus',
        summary: 'Status of the payment of an order of a guest',
        description: 'The status of the order, its deadline and total, whether a payment can be started now, and its newest payment with '
            .'the address to go back to it while it is pending and the order payable.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Id of the order.', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Order-Access-Token', in: 'header', required: true, description: 'The `accessToken` of the placed order.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The status.', content: new OA\JsonContent(ref: '#/components/schemas/PaymentStatusView')),
            new OA\Response(response: 401, description: 'An access token of a session was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). None is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such order for this token (problem details, one text for every case).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'A token of a session was sent and the keys of its issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function status(Request $request, string $orderId): JsonResponse
    {
        return $this->endpoints->status($orderId, self::access($request));
    }

    /**
     * Without the header (or with an empty one) there is no access, which is the 404 of every other case.
     */
    private static function access(Request $request): PaymentAccess
    {
        $token = (string) $request->headers->get(self::ACCESS_TOKEN);

        return '' === $token ? throw new OrderNotFound() : PaymentAccess::guest($token);
    }
}
