<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of a ProductVariant. Only DoctrineProductRepository maps it to and from the domain.
 *
 * values_key is computed by PostgreSQL from option_values (lower case of the canonical jsonb text), so the unique
 * index on (product_id, values_key) rejects a repeated combination also when the domain is bypassed. It is never
 * written from PHP.
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

    #[ORM\Column(
        type: Types::TEXT,
        insertable: false,
        updatable: false,
        columnDefinition: 'TEXT GENERATED ALWAYS AS (lower(option_values::text)) STORED',
        generated: 'ALWAYS',
    )]
    public string $valuesKey;

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
