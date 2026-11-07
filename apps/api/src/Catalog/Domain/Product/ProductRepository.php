<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

interface ProductRepository
{
    /**
     * Inserts the product or overwrites the one with the same id.
     *
     * A write from a view (an expected version) is refused when the stored version is another one, and always raises
     * the version, also when nothing changed. A write without one (creation, the seed) raises it only when something
     * changed. Stale-write detection also compares against the version read in the same unit of work (get() then
     * save() in one handler or transaction). `updated_at` and `updated_by` are written only with a raised version.
     *
     * @param int|null           $expectedVersion the version the change is based on; null for a write without a view
     * @param string|null        $actorId         the staff member of the change (`sub` of the token); null for the seed
     * @param \DateTimeImmutable $at              the time of the change
     *
     * @throws SlugAlreadyTaken
     * @throws VariantAlreadyExists        when a variant id is already used by another product
     * @throws ProductAlreadyExists        when a concurrent insert of the same id wins the race
     * @throws ProductModifiedConcurrently
     */
    public function save(Product $product, ?int $expectedVersion, ?string $actorId, \DateTimeImmutable $at): void;

    /**
     * @throws ProductNotFound
     */
    public function get(ProductId $id): Product;

    /**
     * @return iterable<Product>
     */
    public function all(): iterable;

    public function count(): int;

    public function countWithoutImages(): int;
}
