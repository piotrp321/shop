<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of a ProductVariant. Only DoctrineProductRepository maps it to and from the domain.
 *
 * values_key is the comparison key of the domain (VariantValues::comparisonKey() as JSON), written by
 * DoctrineProductRepository with every variant, so the unique index on (product_id, values_key) agrees with the domain.
 * It is NULL in a row written without it (by the previous version of the code during a deployment, or by direct SQL),
 * which the index does not check.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_product_variant')]
#[ORM\UniqueConstraint(name: 'catalog_product_variant_position_key', columns: ['product_id', 'position'])]
#[ORM\UniqueConstraint(name: 'catalog_product_variant_values_key', columns: ['product_id', 'values_key'])]
class ProductVariantRecord
{
    /** PostgreSQL's default name of the primary key constraint of the table. */
    public const string PRIMARY_KEY_CONSTRAINT = 'catalog_product_variant_pkey';

    /** @var list<string> the values in the order of the axes of the product */
    #[ORM\Column(type: Types::JSONB)]
    public array $optionValues;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $valuesKey = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
        #[ORM\ManyToOne(targetEntity: ProductRecord::class, inversedBy: 'variants')]
        #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public ProductRecord $product,
        #[ORM\Column(type: Types::INTEGER)]
        public int $position,
    ) {
    }
}
