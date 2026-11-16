<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\Dashboard;
use App\Backoffice\Application\Query\GetDashboard;
use App\Backoffice\Application\Query\QueueAccess;
use Doctrine\DBAL\Exception\ConnectionException;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class DashboardController
{
    use HandleTrait;

    /** Attribute of DashboardVoter (Infrastructure): the controller only knows its name. */
    private const string VIEW_SYSTEM_STATUS = 'VIEW_SYSTEM_STATUS';
    /** Attributes of the voters of the modules that own the queues (Ordering, Inventory, Catalog): the controller only knows their names. */
    private const string VIEW_ORDERS = 'VIEW_ORDERS';
    private const string VIEW_PENDING_PAYMENTS = 'VIEW_PENDING_PAYMENTS';
    private const string COMPLETE_REFUND = 'COMPLETE_REFUND';
    private const string MANAGE_STOCK = 'MANAGE_STOCK';
    private const string MANAGE_PRODUCTS = 'MANAGE_PRODUCTS';
    /** Attribute of OrderVoter (Ordering): the sales only for administrators. */
    private const string VIEW_SALES = 'VIEW_SALES';

    public function __construct(
        #[Autowire(service: 'query.bus')] MessageBusInterface $queryBus,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
        $this->messageBus = $queryBus;
    }

    #[Route('/api/v1/admin/dashboard', name: 'backoffice_dashboard', methods: ['GET'])]
    #[Security(name: 'backoffice')]
    #[OA\Get(
        operationId: 'getDashboard',
        summary: 'Dashboard',
        description: 'Catalogue numbers and the queues of work for every staff member: each queue only for the roles that handle it (`queues` fields are null for the others), counted up to 1000. The number of products is an estimate of the database (null before its first statistics). The sales of today, the last 7 and the last 30 days of completed hours only for administrators (`sales` is null for other roles). The system status (services, outbox, queues, search index) '
            .'only for administrators; for other roles `system` is null. A service that is down, a broker whose management API '
            .'does not answer (`queues` is null) or a search index that cannot be counted (`indexedProducts` is null, '
            .'`inSync` is false) does not fail the request.',
        tags: ['Staff'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The dashboard.',
                content: new OA\JsonContent(ref: new Model(type: DashboardResponse::class)),
            ),
            new OA\Response(
                response: 401,
                description: 'Missing or invalid access token (problem details, `WWW-Authenticate: Bearer`).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
            new OA\Response(
                response: 503,
                description: 'The database is unavailable, or the keys of the token issuer cannot be fetched (problem details without detail).',
                content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        try {
            /** @var Dashboard $dashboard */
            $dashboard = $this->handle(new GetDashboard(
                $this->authorization->isGranted(self::VIEW_SYSTEM_STATUS),
                $this->queueAccess(),
                $this->authorization->isGranted(self::VIEW_SALES),
            ));
        } catch (HandlerFailedException $failure) {
            // The sync bus wraps the handler's exception; a lost database connection is an outage, not a bug.
            $wrapped = $failure->getWrappedExceptions();
            if (1 === \count($wrapped) && $wrapped[array_key_first($wrapped)] instanceof ConnectionException) {
                throw new ServiceUnavailableHttpException(previous: $failure);
            }

            throw $failure;
        }

        $response = new JsonResponse(DashboardResponse::of($dashboard));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function queueAccess(): QueueAccess
    {
        return new QueueAccess(
            $this->authorization->isGranted(self::VIEW_ORDERS),
            $this->authorization->isGranted(self::VIEW_PENDING_PAYMENTS),
            $this->authorization->isGranted(self::COMPLETE_REFUND),
            $this->authorization->isGranted(self::MANAGE_STOCK),
            $this->authorization->isGranted(self::MANAGE_PRODUCTS),
        );
    }
}
