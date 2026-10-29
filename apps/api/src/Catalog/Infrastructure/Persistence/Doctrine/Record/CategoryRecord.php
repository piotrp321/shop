<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model of the Category aggregate. Only DoctrineCategoryRepository maps it to and from the domain.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_category')]
#[ORM\Index(name: 'catalog_category_parent_id_idx', columns: ['parent_id'])]
#[ORM\Index(name: 'catalog_category_path_idx', columns: ['path'])]
class CategoryRecord
{
    /** PostgreSQL's default name of the primary key constraint of the table. */
    public const string PRIMARY_KEY_CONSTRAINT = 'catalog_category_pkey';

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id')]
    public ?CategoryRecord $parent = null;

    #[ORM\Column(length: 100)]
    public string $name;

    // The migration adds text_pattern_ops to the index; the mapping cannot express operator classes.
    #[ORM\Column(type: 'text')]
    public string $path;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public readonly string $id,
    ) {
    }
}
