<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Application\Command\PlanPromotion;
use App\Pricing\Application\Port\Clock;
use App\Pricing\Application\Port\VariantsForPricing;
use App\Pricing\Domain\Promotion\Promotion;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Plans the demo promotions through PlanPromotion (the `command.bus`, injected by the parameter name) without an actor,
 * each starting now and lasting its days: the boundaries are scheduled like those of a promotion of the panel. A
 * variant belongs to the first promotion whose patterns match its key, so the periods never overlap. Run after the
 * prices (a variant of a promotion needs a current price).
 */
final readonly class DemoPromotionSeeder
{
    public function __construct(
        private VariantsForPricing $variants,
        private MessageBusInterface $commandBus,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<string> what stops the seed: a promotion without a variant, or with more than a promotion may have
     */
    public function problems(DemoPromotionList $promotions): array
    {
        $problems = [];
        foreach ($this->variantIdsByPromotion($promotions) as $index => $variantIds) {
            $name = $promotions->promotions()[$index]->name;
            if ([] === $variantIds) {
                $problems[] = \sprintf('no demo variant is left for the promotion "%s"', $name);
            } elseif (\count($variantIds) > Promotion::MAX_VARIANTS) {
                $problems[] = \sprintf('the promotion "%s" covers %d demo variants, at most %d are allowed', $name, \count($variantIds), Promotion::MAX_VARIANTS);
            }
        }

        return $problems;
    }

    /**
     * Each promotion is planned in its own transaction. The variants must have prices and no promotion yet (see
     * PricingReset).
     *
     * @return int the number of promotions planned
     *
     * @throws \RuntimeException before any promotion is planned, when `problems` is not empty
     */
    public function seed(DemoPromotionList $promotions): int
    {
        $problems = $this->problems($promotions);
        if ([] !== $problems) {
            throw new \RuntimeException('The demo promotions cannot be planned: '.implode('; ', $problems).'.');
        }

        $now = $this->clock->now();
        foreach ($this->variantIdsByPromotion($promotions) as $index => $variantIds) {
            $promotion = $promotions->promotions()[$index];
            $this->commandBus->dispatch(PlanPromotion::bySeed(
                Uuid::v7()->toRfc4122(),
                $promotion->name,
                $promotion->percentOff,
                $now->modify(\sprintf('+%d days', $promotion->days)),
                $variantIds,
            ));
        }

        return \count($promotions->promotions());
    }

    /**
     * @return array<int, list<string>> variant ids by the index of the promotion, in id order; every index is present
     */
    private function variantIdsByPromotion(DemoPromotionList $promotions): array
    {
        $byPromotion = array_fill(0, \count($promotions->promotions()), []);
        foreach (CatalogVariantPages::of($this->variants) as $variant) {
            foreach ($promotions->promotions() as $index => $promotion) {
                if ($promotion->matches($variant->key)) {
                    $byPromotion[$index][] = $variant->id->toString();

                    break;
                }
            }
        }

        return $byPromotion;
    }
}
