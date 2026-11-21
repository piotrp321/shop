<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\BackofficeOrderList;
use App\Ordering\Application\Query\BackofficeOrderSort;
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
        description: 'A page of orders, newest first (then by id) or in the order of `sort` (by the moment of placement or by the amount, then by id); with `refund=pending` only the orders with a refund to do, the '
            .'nearest due date first (then by id), with `refund=overdue` only those past their due date (a subset of the pending ones in the same order, so the cursor of one leads the other); both take no `sort` and no range of days. The status filter, the search and the range of days combine '
            .'with it (the search with `refund=pending` too); sorting by the amount takes no search and no range of days. The search `q` is an exact order number (`R-100001`, any letter case), '
            .'or a complete email address (a domain with a dot), exactly and in any letter case, otherwise a fragment of 3 to 100 characters of the number, the email or the name '
            .'(any letter case, `%` and `_` mean themselves; `jan@gm` is a fragment); anything else is a 400 at `q`. `placedFrom` and `placedTo` are days of Europe/Warsaw (`YYYY-MM-DD`, both included), `placedFrom` after `placedTo` is a 400. '
            .'Page with the opaque `nextCursor` as `after`, with the same filters and sort (a cursor of another sort is a 400); the first page (without `after`) also '
            .'has the `total` of the matching orders, counted up to 10 000. With `customerId` only the orders of that customer, newest first, with the status filter only (its history on the card of the customer). Administrators, support and the warehouse only.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Only the orders in this status.', schema: new OA\Schema(ref: '#/components/schemas/BackofficeOrderStatus')),
            new OA\Parameter(name: 'refund', in: 'query', required: false, description: '`pending`: only the orders with a refund to do, nearest due date first; `overdue`: only those past their due date (a subset of `pending`, with the same order and cursor). Neither takes `sort`, `placedFrom` or `placedTo`.', schema: new OA\Schema(type: 'string', enum: ['pending', 'overdue'])),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'An order number or a complete email address (exact, any letter case; the email up to 254 characters), otherwise a fragment of 3 to 100 characters of the number, the email or the name (any letter case, `%` and `_` literal); without control characters, trimmed. Not with `sort` by the amount.', schema: new OA\Schema(type: 'string', minLength: 3, maxLength: ListBackofficeOrders::MAX_QUERY_LENGTH)),
            new OA\Parameter(name: 'placedFrom', in: 'query', required: false, description: 'The first day of the range of the placement, `YYYY-MM-DD` in Europe/Warsaw, included. Not with `refund=pending` or `sort` by the amount.', schema: new OA\Schema(type: 'string', format: 'date', pattern: '^[0-9]{4}-[0-9]{2}-[0-9]{2}$')),
            new OA\Parameter(name: 'placedTo', in: 'query', required: false, description: 'The last day of the range of the placement, `YYYY-MM-DD` in Europe/Warsaw, included; not before `placedFrom`. Not with `refund=pending` or `sort` by the amount.', schema: new OA\Schema(type: 'string', format: 'date', pattern: '^[0-9]{4}-[0-9]{2}-[0-9]{2}$')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'The order: `-placedAt` (newest first, the default), `placedAt`, `-total` (the highest amount first) or `total`; the id breaks the ties. Not with `refund=pending`; the amount not with `q` or the range of days.', schema: new OA\Schema(type: 'string', enum: BackofficeOrderListQuery::SORTS, default: '-placedAt')),
            new OA\Parameter(name: 'customerId', in: 'query', required: false, description: 'Only the orders of this customer (the `customerId` of the customer list and of an order), newest first; with `status` only, not with `q`, `sort`, `placedFrom`, `placedTo` or `refund` (a 400 at each).', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 255)),
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'The `nextCursor` of the previous page of the same sort.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: BackofficeOrderListQuery::MAX_CURSOR_LENGTH)),
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
        // The validation has accepted only texts here.
        $q = \is_string($query->q) ? $query->q : null;
        $placedFrom = \is_string($query->placedFrom) ? $query->placedFrom : null;
        $placedTo = \is_string($query->placedTo) ? $query->placedTo : null;
        $customerId = \is_string($query->customerId) ? $query->customerId : null;
        $sort = \is_string($query->sort) ? BackofficeOrderSort::from($query->sort) : null;

        try {
            /** @var BackofficeOrderList $list */
            $list = $this->handle(new ListBackofficeOrders($status, null !== $query->refund, $q, $query->after, $query->perPage, $sort, $placedFrom, $placedTo, 'overdue' === $query->refund, $customerId));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a cursor nobody issued (for this sort) is a bad parameter, a 400.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof InvalidBackofficeOrderCursor) {
                throw new ValidationFailedException($query, new ConstraintViolationList([new ConstraintViolation(BackofficeOrderListQuery::BAD_CURSOR, null, [], $query, 'after', $query->after)]));
            }

            throw $failure;
        }

        $response = new JsonResponse(BackofficeOrderJson::list($list));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
