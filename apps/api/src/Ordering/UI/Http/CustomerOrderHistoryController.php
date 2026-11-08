<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Customer\Application\Security\AuthenticatedCustomer;
use App\Ordering\Application\Query\CustomerOrderList;
use App\Ordering\Application\Query\GetCustomerOrder;
use App\Ordering\Application\Query\InvalidBackofficeOrderCursor;
use App\Ordering\Application\Query\ListCustomerOrders;
use App\Ordering\Application\View\CustomerOrderView;
use App\Ordering\Domain\OrderNotFound;
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
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The order history of the customer of the token (`sub` of the `shop` realm): the list and the detail of their own
 * orders. Every response is `private, no-store` (OrderResponseCacheSubscriber).
 */
#[Security(name: 'shop')]
final class CustomerOrderHistoryController
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/me/orders', name: 'ordering_customer_list', methods: ['GET'])]
    #[OA\Get(
        operationId: 'listCustomerOrders',
        summary: 'Orders of the customer',
        description: 'A page of the orders the customer of the token placed, newest first (then by id); orders of guests and of other customers '
            .'are never in it. Page with the opaque `nextCursor` as `after`; the total is not counted.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'The `nextCursor` of the previous page.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: BackofficeOrderListQuery::MAX_CURSOR_LENGTH)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Page size.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: ListCustomerOrders::MAX_PER_PAGE, default: ListCustomerOrders::DEFAULT_PER_PAGE)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of the history. Possibly an empty one.', content: new OA\JsonContent(ref: '#/components/schemas/CustomerOrderList')),
            new OA\Response(response: 400, description: 'Invalid query parameters or paging cursor (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function list(
        #[CurrentUser] AuthenticatedCustomer $customer,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] CustomerOrderListQuery $query = new CustomerOrderListQuery(),
    ): JsonResponse {
        try {
            /** @var CustomerOrderList $list */
            $list = $this->handle(new ListCustomerOrders($customer->id(), $query->after, $query->perPage));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a cursor nobody issued is a bad parameter, a 400.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof InvalidBackofficeOrderCursor) {
                throw new ValidationFailedException($query, new ConstraintViolationList([new ConstraintViolation('The paging cursor is invalid.', null, [], $query, 'after', $query->after)]));
            }

            throw $failure;
        }

        return self::private(CustomerOrderJson::list($list));
    }

    #[Route('/api/v1/me/orders/{orderId}', name: 'ordering_customer_get', methods: ['GET'])]
    #[OA\Get(
        operationId: 'getCustomerOrder',
        summary: 'An order of the customer',
        description: 'The order with its lines, totals, address and the moments of its statuses. `paymentDeadlinePassed` is computed by the API: '
            .'true only for a new order whose payment deadline has come. No history of changes, notes, data of staff or refunds.',
        tags: ['Ordering'],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Id of the order.', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order.', content: new OA\JsonContent(ref: '#/components/schemas/CustomerOrder')),
            new OA\Response(response: 401, description: 'Missing or invalid access token, also one of the `backoffice` realm (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No such order of the customer: unknown, an id that is no UUID, an order of another customer or of a guest (problem details, one text for every case).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function detail(string $orderId, #[CurrentUser] AuthenticatedCustomer $customer): JsonResponse
    {
        /** @var CustomerOrderView|null $order */
        $order = $this->handle(new GetCustomerOrder($orderId, $customer->id()));
        if (null === $order) {
            throw new OrderNotFound();
        }

        return self::private(CustomerOrderJson::order($order));
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function private(array $body): JsonResponse
    {
        $response = new JsonResponse($body);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
