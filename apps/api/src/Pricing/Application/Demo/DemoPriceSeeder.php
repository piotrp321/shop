<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Application\Command\SetVariantPrice;
use App\Pricing\Application\Port\CatalogVariant;
use App\Pricing\Application\Port\VariantsForPricing;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sets the demo price of every Catalog variant, matched by its key, through SetVariantPrice (the `command.bus`,
 * injected by the parameter name), with the source `seed`. Variants are read in batches, so the catalog is never
 * held in memory.
 */
final readonly class DemoPriceSeeder
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private VariantsForPricing $variants,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @return list<string> keys of the variants no pattern matches, in id order
     */
    public function unpricedKeys(DemoPriceList $prices): array
    {
        $unpriced = [];
        foreach ($this->allVariants() as $variant) {
            if (null === $prices->grossFor($variant->key)) {
                $unpriced[] = $variant->key;
            }
        }

        return $unpriced;
    }

    /**
     * Each price is set in its own transaction. The variants must have no price yet (see PricingReset).
     *
     * @return int the number of prices set
     *
     * @throws \RuntimeException at the first variant no pattern matches
     */
    public function seed(DemoPriceList $prices): int
    {
        $count = 0;
        foreach ($this->allVariants() as $variant) {
            $gross = $prices->grossFor($variant->key)
                ?? throw new \RuntimeException(\sprintf('No demo price pattern matches the variant "%s".', $variant->key));
            $this->commandBus->dispatch(SetVariantPrice::bySeed($variant->id->toString(), $gross));
            ++$count;
        }

        return $count;
    }

    /**
     * @return iterable<CatalogVariant>
     */
    private function allVariants(): iterable
    {
        $after = null;
        do {
            $batch = $this->variants->page($after, self::BATCH_SIZE);
            yield from $batch;
            $after = [] === $batch ? null : $batch[\count($batch) - 1]->id;
        } while (self::BATCH_SIZE === \count($batch));
    }
}
