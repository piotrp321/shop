<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\Port\Clock;
use App\Cart\Application\Port\ExpiredCarts;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class PurgeExpiredCartsHandler
{
    public function __construct(
        private ExpiredCarts $expired,
        private Clock $clock,
    ) {
    }

    public function __invoke(PurgeExpiredCarts $command): int
    {
        return $this->expired->purgeBatch($this->clock->now(), $command->batchSize);
    }
}
