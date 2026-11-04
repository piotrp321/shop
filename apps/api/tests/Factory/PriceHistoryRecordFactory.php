<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Pricing\Infrastructure\Persistence\Doctrine\Record\PriceHistoryRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\ObjectFactory;

/**
 * Builds stored price history rows. The amounts are taken as given (they need not add up): a test writes what it
 * needs to find in the table.
 *
 * @extends ObjectFactory<PriceHistoryRecord>
 */
final class PriceHistoryRecordFactory extends ObjectFactory
{
    public static function class(): string
    {
        return PriceHistoryRecord::class;
    }

    /**
     * Saves a history row at once and returns its id.
     *
     * @param array<string, mixed> $attributes any constructor argument of PriceHistoryRecord, e.g. variantId, changedAt, grossAmount
     */
    public static function store(EntityManagerInterface $entityManager, array $attributes = []): string
    {
        $record = self::new($attributes)->create();
        $entityManager->persist($record);
        $entityManager->flush();

        return $record->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => (string) Uuid::v7(),
            'variantId' => self::faker()->uuid(),
            'currency' => 'PLN',
            'grossAmount' => 12300,
            'netAmount' => 10000,
            'vatAmount' => 2300,
            'vatRateBp' => 2300,
            'taxClass' => 'standard',
            'changedAt' => new \DateTimeImmutable('2026-10-01 10:00:00+00:00'),
            'source' => 'staff',
            'actorId' => 'staff-sub',
            'actorName' => 'Anna Admin',
        ];
    }
}
