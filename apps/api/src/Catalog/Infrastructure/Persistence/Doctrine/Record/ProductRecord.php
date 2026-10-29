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
class ProductRecord
{
    public const string SLUG_CONSTRAINT = 'catalog_product_slug_key';

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
    public string $taxCategory;

    /** @var list<array{key: string, alt: string}> in position order */
    #[ORM\Column(type: Types::JSONB)]
    public array $images;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
    ) {
        $this->additionalCategories = new ArrayCollection();
    }
}
