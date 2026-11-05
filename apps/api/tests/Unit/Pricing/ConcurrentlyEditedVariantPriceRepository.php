<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Price\VariantPriceRepository;
use App\Pricing\Domain\Tax\VatRates;

/**
 * Plays a staff member who changes the gross of a price right before the code under test saves it, so that save
 * is refused. A refused save closes the session, and any use of a closed session fails, as with Doctrine.
 */
final class ConcurrentlyEditedVariantPriceRepository implements VariantPriceRepository
{
    /** @var array<string, int> variant id => edits still to play */
    private array $edits = [];

    public function __construct(
        private readonly InMemoryVariantPriceRepository $inner,
        private readonly InMemoryPersistenceSession $session,
        private readonly VatRates $rates,
    ) {
    }

    /**
     * @param int $times how many saves of the price are preceded by a competing edit
     */
    public function editBeforeSave(string $variantId, int $times): void
    {
        $this->edits[$variantId] = $times;
    }

    public function find(VariantId $id): ?VariantPrice
    {
        $this->assertOpen();

        return $this->inner->find($id);
    }

    public function findMany(array $ids): array
    {
        $this->assertOpen();

        return $this->inner->findMany($ids);
    }

    public function page(?VariantId $after, int $limit): array
    {
        $this->assertOpen();

        return $this->inner->page($after, $limit);
    }

    public function save(VariantPrice $price): void
    {
        $this->assertOpen();
        $id = $price->id()->toString();
        if (($this->edits[$id] ?? 0) > 0) {
            --$this->edits[$id];
            $competing = $this->inner->find($price->id());
            \assert(null !== $competing);
            $competing->changeGross(GrossPrice::pln($competing->breakdown()->gross()->amount() + 100), $this->rates, PriceChangeOrigin::staff('sub-2', 'Jan Admin'));
            $this->inner->save($competing);
        }

        try {
            $this->inner->save($price);
        } catch (PriceModifiedConcurrently $e) {
            $this->session->close();

            throw $e;
        }
    }

    private function assertOpen(): void
    {
        if (!$this->session->open) {
            throw new \LogicException('The session is closed; it must be reset first.');
        }
    }
}
