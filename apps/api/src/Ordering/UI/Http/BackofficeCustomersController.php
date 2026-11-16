<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\BackofficeCustomerList;
use App\Ordering\Application\Query\BackofficeCustomerSort;
use App\Ordering\Application\Query\InvalidBackofficeCustomerCursor;
use App\Ordering\Application\Query\ListBackofficeCustomers;
use Nelmio\ApiDocBundle\Attribute\Model;
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
 * The customer list of the panel, from the customer summaries of Ordering. Every response is `private, no-store`
 * (OrderResponseCacheSubscriber).
 */
final class BackofficeCustomersController
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/customers', name: 'ordering_backoffice_customers', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'listCustomers',
        summary: 'Customer list',
        description: 'A page of the customers: the accounts of the store with at least one order (guests are no customers), the newest last order first '
            .'(then by id) or in the order of `sort`. The search `q` is a fragment of 3 to 100 characters of the email or the name (any letter case, `%` and `_` mean themselves), '
            .'only with the sort by the last order. Page with the opaque `nextCursor` as `after`, with the same `q` and `sort` (a cursor of another sort is a 400); '
            .'the first page (without `after`) also has the `total` of the matching customers, counted up to 10 000. Administrators and support only.',
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'A fragment of the email or the name, 3 to 100 characters once trimmed, without control characters. Only with `sort=-lastOrderAt`.', schema: new OA\Schema(type: 'string', minLength: 3, maxLength: 100)),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'The order: `-lastOrderAt` (the newest last order first, the default), `name` (A to Z, any letter case), `-orderCount` (the most orders first) or `-paidGross` (the highest paid value first); the customer id breaks the ties.', schema: new OA\Schema(type: 'string', enum: BackofficeCustomerListQuery::SORTS, default: '-lastOrderAt')),
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'The `nextCursor` of the previous page of the same sort.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: BackofficeCustomerListQuery::MAX_CURSOR_LENGTH)),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Page size.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: ListBackofficeCustomers::MAX_PER_PAGE, default: ListBackofficeCustomers::DEFAULT_PER_PAGE)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of the list. Possibly an empty one.', content: new OA\JsonContent(ref: new Model(type: BackofficeCustomerListResponse::class))),
            new OA\Response(response: 400, description: 'Invalid query parameters or paging cursor, also a cursor of another sort or the search with another sort (problem details with `violations`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not see customers (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(BackofficeOrderAttributes::VIEW_CUSTOMERS)]
    public function __invoke(#[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] BackofficeCustomerListQuery $query = new BackofficeCustomerListQuery()): JsonResponse
    {
        // The validation has accepted only these kinds here.
        $q = \is_string($query->q) ? $query->q : null;
        $sort = \is_string($query->sort) ? BackofficeCustomerSort::from($query->sort) : null;
        $after = \is_string($query->after) ? $query->after : null;

        try {
            /** @var BackofficeCustomerList $list */
            $list = $this->handle(new ListBackofficeCustomers($q, $sort, $after, $query->pageSize() ?? ListBackofficeCustomers::DEFAULT_PER_PAGE));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a cursor nobody issued (for this sort) is a bad parameter, a 400.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && reset($wrapped) instanceof InvalidBackofficeCustomerCursor) {
                throw new ValidationFailedException($query, new ConstraintViolationList([new ConstraintViolation(BackofficeCustomerListQuery::BAD_CURSOR, null, [], $query, 'after', $after)]));
            }

            throw $failure;
        }

        $response = new JsonResponse(BackofficeCustomerListResponse::of($list));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
