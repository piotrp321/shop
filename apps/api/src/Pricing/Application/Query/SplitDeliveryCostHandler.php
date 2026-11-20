<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Domain\Basket\DeliveryCostSplit;
use App\Pricing\Domain\Price\LineBreakdown;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class SplitDeliveryCostHandler
{
    /**
     * @throws \InvalidArgumentException for a cost or goods out of range, a rate out of 0–10000 basis points, or goods
     *                                   worth nothing (the caller always has goods of at least 1 grosz)
     */
    public function __invoke(SplitDeliveryCost $query): DeliveryCostShares
    {
        return new DeliveryCostShares(array_map(
            static fn (LineBreakdown $share): DeliveryCostShare => new DeliveryCostShare(
                $share->rate()->basisPoints(),
                $share->gross()->amount(),
                $share->net()->amount(),
                $share->vat()->amount(),
            ),
            DeliveryCostSplit::of($query->costGross, $query->goodsByRate),
        ));
    }
}
