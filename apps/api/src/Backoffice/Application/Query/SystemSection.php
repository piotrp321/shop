<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class SystemSection
{
    /**
     * @param list<ServiceStatus>    $services in a fixed order
     * @param list<QueueStatus>|null $queues   null when the broker's management API does not answer
     */
    public function __construct(
        public array $services,
        public int $outboxPending,
        public ?array $queues,
        public SearchIndexStatus $searchIndex,
    ) {
    }
}
