<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Messaging;

use App\Catalog\Application\Command\ReindexCategoryBatch;
use App\Catalog\Application\Port\CategoryReindexBatches;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends the batch on command.bus; the routing puts it on the Doctrine transport `jobs`, on the connection of the
 * caller's transaction, so the row is written or rolled back with the state of the run.
 */
final readonly class MessengerCategoryReindexBatches implements CategoryReindexBatches
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    public function send(string $runId, int $batch): void
    {
        $this->commandBus->dispatch(new ReindexCategoryBatch($runId, $batch));
    }
}
