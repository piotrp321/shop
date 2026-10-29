<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\SystemSection;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The state of the system, for administrators.', required: ['services', 'outbox', 'queues', 'searchIndex'])]
final readonly class DashboardSystemResponse
{
    /**
     * @param list<DashboardServiceResponse>    $services
     * @param list<DashboardQueueResponse>|null $queues
     */
    public function __construct(
        #[OA\Property(
            description: 'Always in this order: postgres, redis, elasticsearch, rabbitmq, keycloak, garage, imgproxy, mailpit. A service that does not answer within 1 s is down.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: DashboardServiceResponse::class)),
        )]
        public array $services,
        #[OA\Property(ref: new Model(type: DashboardOutboxResponse::class))]
        public DashboardOutboxResponse $outbox,
        #[OA\Property(
            description: 'Queues of the broker. Null when its management API does not answer.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: DashboardQueueResponse::class)),
            nullable: true,
        )]
        public ?array $queues,
        #[OA\Property(ref: new Model(type: DashboardSearchIndexResponse::class))]
        public DashboardSearchIndexResponse $searchIndex,
    ) {
    }

    public static function of(SystemSection $system): self
    {
        return new self(
            array_map(DashboardServiceResponse::of(...), $system->services),
            new DashboardOutboxResponse($system->outboxPending),
            null === $system->queues ? null : array_map(DashboardQueueResponse::of(...), $system->queues),
            DashboardSearchIndexResponse::of($system->searchIndex),
        );
    }
}
