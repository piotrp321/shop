<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Command\ChangeTrackingNumber;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Shared\UI\Http\RequiredKeysBody;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A staff member adds, replaces or removes the tracking number of a shipped or delivered order. The checks run in the
 * order of the specification: the token (401), changing the number (403, the attribute, before the body), the body
 * (400, 415, 422), the order (404), then its version and status (409). Every response is `private, no-store`
 * (OrderResponseCacheSubscriber).
 */
final class ChangeTrackingNumberController
{
    use HandleTrait;

    public function __construct(
        #[Autowire(service: 'command.bus')] MessageBusInterface $commandBus,
        private readonly BackofficeOrderResponder $responder,
    ) {
        $this->messageBus = $commandBus;
    }

    #[Route('/api/v1/admin/orders/{orderId}/tracking-number', name: 'ordering_backoffice_change_tracking_number', requirements: ['orderId' => BackofficeOrderController::UUID], methods: ['PUT'])]
    #[Security(name: 'backoffice')]
    #[OA\Put(
        operationId: 'changeOrderTrackingNumber',
        summary: 'Add, replace or remove the tracking number of an order',
        description: 'Sets the tracking number of a shipped or delivered order (1 to 40 ASCII letters, digits and hyphens), replacing the '
            .'one it has; null or a text of nothing but white space removes it. Administrators and the warehouse only. Only an order '
            .'that is shipped or delivered takes the change. The number is not an event of the order: no history entry is added and '
            .'no email is sent (the customer sees the current number and its link in the order; the email of the shipment keeps the '
            .'number it was sent with). Send the `version` of the order you saw: an order changed since then is a 409 and nothing is '
            .'changed. The change raises the version.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Order id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ChangeTrackingNumberRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'The order as saved.', content: new OA\JsonContent(ref: '#/components/schemas/BackofficeOrder')),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member is neither an administrator nor in the warehouse (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such order, or the id is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The order changed since the given version, or it is neither shipped nor delivered (problem details with a stable `type`, see `BackofficeOrderConflictProblem`). Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/BackofficeOrderConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'The number is not a text or is of a wrong shape, the version is not an integer of at least 1, or a key is missing or of a wrong type (problem details with Polish `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(BackofficeOrderAttributes::CHANGE_TRACKING_NUMBER)]
    public function __invoke(
        string $orderId,
        #[RequiredKeysBody]
        ChangeTrackingNumberRequest $change,
    ): JsonResponse {
        // Validated by the payload mapping: a string or null, and an integer.
        \assert((null === $change->trackingNumber || \is_string($change->trackingNumber)) && \is_int($change->version));

        $order = $this->handle(new ChangeTrackingNumber($orderId, $change->version, $change->trackingNumber));
        \assert($order instanceof BackofficeOrderView);

        return $this->responder->respond($order);
    }
}
