<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\ProductPriceRecord;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\ObjectFactory;

/**
 * Builds stored price rows. Net and VAT come from PriceBreakdown::fromGross at the given rate, which may differ
 * from the rate of the class (an outdated rate) and the class may have no rate at all: the factory writes what a
 * test needs to find in the table, not what the domain would have written today.
 *
 * @extends ObjectFactory<ProductPriceRecord>
 */
final class ProductPriceRecordFactory extends ObjectFactory
{
    public static function class(): string
    {
        return ProductPriceRecord::class;
    }

    /**
     * Saves a price row at once and returns the id of its product.
     *
     * @param array<string, mixed> $attributes productId, gross (grosze), taxClass, vatRateBp, currency
     */
    public static function store(EntityManagerInterface $entityManager, array $attributes = []): string
    {
        $record = self::new($attributes)->create();
        $entityManager->persist($record);
        $entityManager->flush();

        return $record->productId;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'productId' => self::faker()->uuid(),
            'gross' => 12300,
            'taxClass' => 'standard',
            'vatRateBp' => 2300,
            'currency' => 'PLN',
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(self::instantiate(...));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function instantiate(array $attributes): ProductPriceRecord
    {
        ['productId' => $productId, 'gross' => $gross, 'taxClass' => $taxClass, 'vatRateBp' => $basisPoints, 'currency' => $currency] = $attributes;
        \assert(\is_string($productId) && \is_int($gross) && \is_string($taxClass) && \is_int($basisPoints) && \is_string($currency));

        $breakdown = PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints));
        $record = new ProductPriceRecord($productId);
        $record->currency = $currency;
        $record->grossAmount = $breakdown->gross()->amount();
        $record->netAmount = $breakdown->net()->amount();
        $record->vatAmount = $breakdown->vat()->amount();
        $record->vatRateBp = $basisPoints;
        $record->taxClass = $taxClass;
        $record->updatedAt = new \DateTimeImmutable();

        return $record;
    }
}
