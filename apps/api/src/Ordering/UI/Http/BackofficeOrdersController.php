<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\BackofficeOrderList;
use App\Ordering\Application\Query\InvalidBackofficeOrderCursor;
use App\Ordering\Application\Query\ListBackofficeOrders;
use App\Ordering\Domain\OrderStatus;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The order list of the panel. Every response is `private, no-store` (OrderResponseCacheSubscriber).
 */
final class BackofficeOrdersController
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/orders', name: 'ordering_backoffice_orders', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'listOrders',
        summary: 'Order list',
        description: 'A page of orders, newest first (then by id); with `refund=pending` only the orders with a refund to do, the '
            .'nearest due date first (then by id), with `overdue` for the ones past it. The status filter and the search combine '
            .'with it. The search is an exact order number (`R-100001`, any letter case) or an exact email (any letter case); '
            .'anything else finds nothing. Page with the opaque `nextCursor` as `after`, with the same filters; the total is not '
            .'counted. Administrators, support and the warehouse only.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Only the orders in this status.', schema: new OA\Schema(ref: '#/components/schemas/BackofficeOrderStatus')),
            new OA\Parameter(name: 'refund', in: 'query', required: false, description: '`pending`: only the orders with a refund to do, nearest due date first.', schema: new OA\Schema(type: 'string', enum: ['pending'])),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'An order number or an email, exact, any letter case; 1 to 254 characters once trimmed.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: ListBackofficeOrders::MAX_QUERY_LENGTH)),
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'The `nextCursor` of the previous page.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: BackofficeOrderListQuery::MAX_CURSOR_LENGTH)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Page size.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: ListBackofficeOrders::MAX_PER_PAGE, default: ListBackofficeOrders::DEFAULT_PER_PAGE)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of the list. Possibly an empty one.', content: new OA\JsonContent(ref: '#/components/schemas/BackofficeOrderList')),
            new OA\Response(response: 400, description: 'Invalid query parameters or paging cursor, also a cursor of the other sort (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not see orders (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(BackofficeOrderAttributes::VIEW_ORDERS)]
    public function __invoke(#[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] BackofficeOrderListQuery $query = new BackofficeOrderListQuery()): JsonResponse
    {
        $status = null === $query->status ? null : OrderStatus::from($query->status);

        try {
            /** @var BackofficeOrderList $list */
            $list = $this->handle(new ListBackofficeOrders($status, null !== $query->refund, $query->q, $query->after, $query->perPage));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a cursor nobody issued (for this sort) is a bad parameter, a 400.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof InvalidBackofficeOrderCursor) {
                throw new ValidationFailedException($query, new ConstraintViolationList([new ConstraintViolation('The paging cursor is invalid.', null, [], $query, 'after', $query->after)]));
            }

            throw $failure;
        }

        $response = new JsonResponse(BackofficeOrderJson::list($list));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
