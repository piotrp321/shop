<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Query\MessagingSection;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The messages of the queues in the database (the outbox and the commands), judged by the API.', required: ['healthy', 'failed', 'transports'])]
final readonly class DashboardMessagesResponse
{
    /**
     * @param list<DashboardTransportResponse> $transports
     */
    public function __construct(
        #[OA\Property(description: 'Nothing is in `failed` and no queue stands.', example: true)]
        public bool $healthy,
        #[OA\Property(ref: new Model(type: DashboardFailedMessagesResponse::class))]
        public DashboardFailedMessagesResponse $failed,
        #[OA\Property(
            description: 'Always in this order: outbox, scheduled, payment_simulator, promotions, jobs, indexing_finalize.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: DashboardTransportResponse::class)),
        )]
        public array $transports,
    ) {
    }

    public static function of(MessagingSection $messages): self
    {
        return new self(
            $messages->healthy,
            DashboardFailedMessagesResponse::of($messages->failed),
            array_map(DashboardTransportResponse::of(...), $messages->transports),
        );
    }
}
