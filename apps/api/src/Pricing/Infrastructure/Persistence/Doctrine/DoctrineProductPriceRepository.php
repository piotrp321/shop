<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceHistoryEntry;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Price\ProductPriceRepository;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\PriceHistoryRecord;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\ProductPriceRecord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductPriceRepository implements ProductPriceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(ProductId $id): ?ProductPrice
    {
        $record = $this->entityManager->find(ProductPriceRecord::class, $id->toString());

        return null === $record ? null : $this->toDomain($record);
    }

    /**
     * The price was read at version() − 1 when it has unsaved history (0 for a new one). The versioned UPDATE checks
     * that version and the primary key refuses a second first price, both inside the transaction that appends
     * the history entries.
     */
    public function save(ProductPrice $price): void
    {
        $entries = $price->releaseHistory();
        if ([] === $entries) {
            return;
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($price, $entries): void {
                $this->write($price, $price->version() - 1, $entries);
            });
        } catch (OptimisticLockException) {
            throw new PriceModifiedConcurrently();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), ProductPriceRecord::PRIMARY_KEY_CONSTRAINT)) {
                throw new PriceModifiedConcurrently();
            }

            throw $e;
        }
    }

    /**
     * @param list<PriceHistoryEntry> $entries
     */
    private function write(ProductPrice $price, int $readVersion, array $entries): void
    {
        $id = $price->id()->toString();
        $record = $this->entityManager->find(ProductPriceRecord::class, $id);
        if (0 === $readVersion) {
            if (null !== $record) {
                throw new PriceModifiedConcurrently();
            }
            $record = new ProductPriceRecord($id);
            $this->entityManager->persist($record);
        } elseif (null === $record) {
            throw new PriceModifiedConcurrently();
        } else {
            // The versioned UPDATE compares with this value, so a record cached in the identity map with a newer
            // version cannot make a stale write pass.
            $record->version = $readVersion;
        }

        $breakdown = $price->breakdown();
        $record->currency = $breakdown->currency()->value;
        $record->grossAmount = $breakdown->gross()->amount();
        $record->netAmount = $breakdown->net()->amount();
        $record->vatAmount = $breakdown->vat()->amount();
        $record->vatRateBp = $breakdown->rate()->basisPoints();
        $record->taxClass = $price->taxClass()->toString();
        $record->updatedAt = $price->updatedAt();

        foreach ($entries as $entry) {
            $this->entityManager->persist($this->historyRecord($entry));
        }
        $this->entityManager->flush();

        // Doctrine sets the version it wrote. A change always moves updated_at, so the row is always written; the
        // check keeps "no history entry without a new version" even if it were not.
        if ($record->version !== $price->version()) {
            throw new \LogicException('The price row was not written with the version of the price.');
        }
    }

    private function historyRecord(PriceHistoryEntry $entry): PriceHistoryRecord
    {
        $breakdown = $entry->breakdown();

        return new PriceHistoryRecord(
            // Time-ordered, so entries of the same second keep their order.
            Uuid::v7()->toRfc4122(),
            $entry->productId()->toString(),
            $breakdown->currency()->value,
            $breakdown->gross()->amount(),
            $breakdown->net()->amount(),
            $breakdown->vat()->amount(),
            $breakdown->rate()->basisPoints(),
            $entry->taxClass()->toString(),
            $entry->changedAt(),
            $entry->origin()->source()->value,
            $entry->origin()->actorId(),
            $entry->origin()->actorName(),
        );
    }

    private function toDomain(ProductPriceRecord $record): ProductPrice
    {
        return ProductPrice::reconstitute(
            ProductId::fromString($record->productId),
            PriceBreakdown::restore(
                GrossPrice::of(Money::of($record->grossAmount, Currency::from($record->currency))),
                $record->netAmount,
                $record->vatAmount,
                VatRate::fromBasisPoints($record->vatRateBp),
            ),
            TaxClass::fromString($record->taxClass),
            $record->version,
            $record->updatedAt,
        );
    }
}
