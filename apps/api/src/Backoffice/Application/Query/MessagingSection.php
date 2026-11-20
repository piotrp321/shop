<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

/**
 * The messages of the database judged: the dashboard shows it and app:messaging:check turns it into its exit code.
 */
final readonly class MessagingSection
{
    /**
     * @param list<TransportStatus> $transports
     * @param bool                  $healthy    nothing failed and no queue stands
     */
    public function __construct(
        public FailedMessages $failed,
        public array $transports,
        public bool $healthy,
    ) {
    }
}
