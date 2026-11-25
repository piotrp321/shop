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
     * @throws SkuAlreadyTaken             when a variant of another product has a SKU of the product (the unique index decides a race)
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
     * The given SKUs that a variant of a product other than `$product` has, drafts too: the SKU is unique among all the
     * variants of all the products, and the variants of the product itself do not count (they are rewritten with it).
     *
     * @param list<Sku> $skus
     *
     * @return list<Sku> the taken ones, in the order given
     */
    public function skusOfOtherProducts(ProductId $product, array $skus): array;
}
