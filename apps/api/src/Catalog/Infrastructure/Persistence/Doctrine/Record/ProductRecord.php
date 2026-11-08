<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the Product aggregate. Only DoctrineProductRepository maps it to and from the domain.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_product')]
#[ORM\UniqueConstraint(name: self::SLUG_CONSTRAINT, columns: ['slug'])]
#[ORM\Index(name: 'catalog_product_primary_category_id_idx', columns: ['primary_category_id'])]
// The migration makes this one a GIN index with gin_trgm_ops (pg_trgm) for substring search of the name; the mapping
// cannot express an access method or operator class.
#[ORM\Index(name: 'catalog_product_name_trgm_idx', columns: ['name'])]
#[ORM\Index(name: 'catalog_product_name_id_idx', columns: ['name', 'id'])]
// The predicate as PostgreSQL reports it, so the mapping and the migrated schema compare equal.
#[ORM\Index(name: 'catalog_product_draft_name_id_idx', columns: ['name', 'id'], options: ['where' => "((status)::text = 'draft'::text)"])]
class ProductRecord
{
    public const string SLUG_CONSTRAINT = 'catalog_product_slug_key';
    /** PostgreSQL's default name of the primary key constraint of the table. */
    public const string PRIMARY_KEY_CONSTRAINT = 'catalog_product_pkey';

    #[ORM\Column(length: 200)]
    public string $slug;

    #[ORM\Column(length: 200)]
    public string $name;

    #[ORM\Column(length: 500)]
    public string $shortDescription;

    #[ORM\ManyToOne(targetEntity: CategoryRecord::class)]
    #[ORM\JoinColumn(name: 'primary_category_id', referencedColumnName: 'id', nullable: false)]
    public CategoryRecord $primaryCategory;

    /** @var Collection<int, CategoryRecord> */
    #[ORM\ManyToMany(targetEntity: CategoryRecord::class)]
    #[ORM\JoinTable(name: 'catalog_product_category')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'category_id', referencedColumnName: 'id')]
    public Collection $additionalCategories;

    #[ORM\Column(length: 32)]
    public string $taxClass;

    /** @var list<array{key: string, alt: string}> in position order */
    #[ORM\Column(type: Types::JSONB)]
    public array $images;

    /** @var list<string> names of the variant axes, in order */
    #[ORM\Column(type: Types::JSONB)]
    public array $variantAxes = [];

    /** @var Collection<int, ProductVariantRecord> in position order */
    #[ORM\OneToMany(targetEntity: ProductVariantRecord::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $variants;

    /** A ProductStatus value. */
    #[ORM\Column(length: 16)]
    public string $status;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $firstPublishedAt = null;

    /** The staff member (`sub` of the backoffice token) of the last write that raised the version; null for the seed. */
    #[ORM\Column(nullable: true)]
    public ?string $updatedBy = null;

    /** The time of the last write that raised the version. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    /** Doctrine optimistic locking; the domain gets it as the version the product was read at. */
    #[ORM\Version]
    #[ORM\Column(type: Types::BIGINT)]
    public int $version = 1;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
    ) {
        $this->additionalCategories = new ArrayCollection();
        $this->variants = new ArrayCollection();
    }
}
