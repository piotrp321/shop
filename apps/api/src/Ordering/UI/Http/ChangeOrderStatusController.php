<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Domain\StaffTransition;
use App\Shared\UI\Http\RequiredKeysBody;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A staff member changes the status of an order. The checks run in the order of the specification: the token (401),
 * seeing orders (403, the attribute, before the body), the body (400, 415, 422), the role for the transition of the
 * body (403), the order (404), then its version and status (409). Every response is `private, no-store`
 * (OrderResponseCacheSubscriber).
 */
final class ChangeOrderStatusController
{
    use HandleTrait;

    public function __construct(
        #[Autowire(service: 'command.bus')] MessageBusInterface $commandBus,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly BackofficeOrderResponder $responder,
    ) {
        $this->messageBus = $commandBus;
    }

    #[Route('/api/v1/admin/orders/{orderId}/status-changes', name: 'ordering_backoffice_change_status', requirements: ['orderId' => BackofficeOrderController::UUID], methods: ['POST'])]
    #[Security(name: 'backoffice')]
    #[OA\Post(
        operationId: 'changeOrderStatus',
        summary: 'Change the status of an order',
        description: 'Ships (the reserved units leave the stock), delivers, cancels (a paid order only; the reservation is released and '
            .'a refund of the gross total is due in 14 days) or registers the return of an order (shipped or delivered; a refund is '
            .'due in 14 days, the stock stays). Administrators and the warehouse ship and deliver, administrators and support cancel '
            .'and register returns. A note is required to cancel and to register a return. Send the `version` of the order you saw: '
            .'an order changed since then is a 409 and nothing is changed, so repeating a change that succeeded is a 409 too. The '
            .'history entry names the staff member of the token. The customer gets an email about a shipment, a cancellation and '
            .'a return.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Order id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ChangeOrderStatusRequest::class))),
        responses: [
            new OA\Response(response: 200, description: 'The order as saved.', content: new OA\JsonContent(ref: '#/components/schemas/BackofficeOrder')),
            new OA\Response(response: 400, description: 'The body is not valid JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not see orders, or may not make this change (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such order, or the id is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 409, description: 'The order changed since the given version, or its status does not allow the change (problem details with a stable `type`, see `BackofficeOrderConflictProblem`). Nothing is changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/BackofficeOrderConflictProblem'))),
            new OA\Response(response: 415, description: 'The body is not JSON (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'The transition is unknown, the version is not an integer of at least 1, the note breaks its rules or is missing to cancel or register a return, or a key is missing or of a wrong type (problem details with Polish `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(BackofficeOrderAttributes::VIEW_ORDERS)]
    public function __invoke(
        string $orderId,
        #[CurrentUser] AuthenticatedStaffMember $staffMember,
        #[RequiredKeysBody]
        ChangeOrderStatusRequest $change,
    ): JsonResponse {
        // Validated by the payload mapping: a transition, an integer and a string or null.
        \assert(\is_string($change->transition) && \is_int($change->version) && (null === $change->note || \is_string($change->note)));
        $transition = StaffTransition::from($change->transition);
        if (!$this->authorization->isGranted(BackofficeOrderAttributes::CHANGE_ORDER_STATUS, $transition)) {
            throw new AccessDeniedException();
        }

        $order = $this->handle(new ChangeOrderStatus($orderId, $transition, $change->version, $change->note, $staffMember->id(), $staffMember->username()));
        \assert($order instanceof BackofficeOrderView);

        return $this->responder->respond($order);
    }
}
