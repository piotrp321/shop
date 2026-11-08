<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageRepository;
use App\Pricing\Domain\DiscountCode\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Releases the active usage of the order at now (decisions D4, A4). Only the row of the usage changes, the code is
 * not locked, so the expiry (order, then reservation and stock, then usage) never forms a cycle with a placement
 * (cart, then code, then stock). An order without a usage, or with one released already, changes nothing.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReleaseDiscountCodeUsageHandler
{
    public function __construct(
        private DiscountCodeUsageRepository $usages,
        private Clock $clock,
    ) {
    }

    public function __invoke(ReleaseDiscountCodeUsage $command): void
    {
        $usage = $this->usages->ofOrder(OrderId::fromString($command->orderId));
        if (null === $usage || !$usage->isActive()) {
            return;
        }

        $this->usages->save($usage->release($this->clock->now()));
    }
}
