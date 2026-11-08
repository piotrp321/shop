<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\GetBackofficeOrder;
use App\Ordering\Application\View\BackofficeOrderView;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * An order in the panel. Every response is `private, no-store` (OrderResponseCacheSubscriber).
 */
final class BackofficeOrderController
{
    use HandleTrait;

    /** A UUID in any letter case; anything else matches no route (404), like an unknown order. */
    public const string UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        #[Autowire(service: 'query.bus')] MessageBusInterface $queryBus,
        private readonly BackofficeOrderResponder $responder,
    ) {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/orders/{orderId}', name: 'ordering_backoffice_order', requirements: ['orderId' => self::UUID], methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getOrder',
        summary: 'Order detail',
        description: 'The order with its contact, address, lines, totals, the moments of its statuses, the payment that paid it, '
            .'its history oldest first, its refund and its `version`, which the next change of the status or the completion of '
            .'the refund sends. `allowedTransitions` and `canCompleteRefund` say what the staff member of the token may do with it '
            .'now. Administrators, support and the warehouse only.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Order id (UUID).', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order.', content: new OA\JsonContent(ref: '#/components/schemas/BackofficeOrder')),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not see orders (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such order, or the id is not a UUID (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(BackofficeOrderAttributes::VIEW_ORDERS)]
    public function __invoke(string $orderId): JsonResponse
    {
        $order = $this->handle(new GetBackofficeOrder($orderId));
        \assert($order instanceof BackofficeOrderView);

        return $this->responder->respond($order);
    }
}
