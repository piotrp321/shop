<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Ordering\Application\Command\CompleteRefund;
use App\Ordering\Application\View\BackofficeOrderView;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * A staff member marks the refund of an order made. The checks run in the order of the specification: the token
 * (401), completing refunds (403, the attribute, before the body), the body (400, 415, 422), the order (404), then its
 * version and refund (409). Every response is `private, no-store` (OrderResponseCacheSubscriber).
 */
final class CompleteRefundController
{
    use HandleTrait;

    public function __construct(
        #[Autowire(service: 'command.bus')] MessageBusInterface $commandBus,
        private readonly BackofficeOrderResponder $responder,
    ) {
        $this->messageBus = $commandBus;
    }

    #[Route('/api/v1/admin/orders/{orderId}/refund-completion', name: 'ordering_backoffice_complete_refund', requirements: ['orderId' => BackofficeOrderController::UUID], methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'completeOrderRefund',
        summary: 'Mark the refund of an order made',
        description: 'Marks the refund to do of an order (cancelled by staff or returned) as made, with the staff member of the token, '
            .'the moment and an optional note; the money itself is transferred by hand, outside the system. The status of the order '
            .'stays and no history entry is added. Administrators and support only. Send the `version` of the order you saw: an '
            .'order changed since then is a 409, so repeating a completion that succeeded is a 409 too.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Order id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CompleteRefundRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'The order as saved.', content: new OA\JsonContent(ref: '#/components/schemas/BackofficeOrder')),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member is neither an administrator nor in support (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such order, or the id is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The order changed since the given version, or it has no refund to do (problem details with a stable `type`, see `BackofficeOrderConflictProblem`). Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/BackofficeOrderConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'The version is not an integer of at least 1, the note breaks its rules, or a key is missing or of a wrong type (problem details with Polish `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(BackofficeOrderAttributes::COMPLETE_REFUND)]
    public function __invoke(
        string $orderId,
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true],
            validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            mapWhenEmpty: true,
        )]
        CompleteRefundRequest $completion,
    ): JsonResponse {
        // Validated by the payload mapping: an integer and a string or null.
        \assert(\is_int($completion->version) && (null === $completion->note || \is_string($completion->note)));

        $order = $this->handle(new CompleteRefund($orderId, $completion->version, $completion->note, $staffMember->id(), $staffMember->username()));
        \assert($order instanceof BackofficeOrderView);

        return $this->responder->respond($order);
    }
}
