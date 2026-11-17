<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\GetBackofficeCustomer;
use App\Ordering\Application\View\BackofficeCustomerView;
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
 * The card of a customer in the panel. Every response is `private, no-store` (OrderResponseCacheSubscriber).
 */
final class BackofficeCustomerController
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/customers/{customerId}', name: 'ordering_backoffice_customer', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getCustomer',
        summary: 'Customer card',
        description: 'The contact of the newest order of the customer, the summary of its orders (all, the paid ones and their gross value without the refunds taken off, the first and the last one) '
            .'and up to 10 distinct shipping addresses of its last 50 orders, the one used last first. Its orders are the order list with `customerId`. Administrators and support only.',
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'The id of the account of the store (`sub` of its token), as in `customerId` of the list.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 255)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The customer.', content: new OA\JsonContent(ref: new Model(type: BackofficeCustomerResponse::class))),
            new OA\Response(response: 401, description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 403, description: 'The staff member may not see customers (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'No customer with this id: no account with an order (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The keys of the token issuer cannot be fetched (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    #[IsGranted(BackofficeOrderAttributes::VIEW_CUSTOMERS)]
    public function __invoke(string $customerId): JsonResponse
    {
        $customer = $this->handle(new GetBackofficeCustomer($customerId));
        \assert($customer instanceof BackofficeCustomerView);

        $response = new JsonResponse(BackofficeCustomerResponse::of($customer));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
