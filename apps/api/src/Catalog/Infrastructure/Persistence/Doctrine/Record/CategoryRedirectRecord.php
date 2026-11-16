<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Record;

use Doctrine\ORM\Mapping as ORM;

/**
 * An old address of a category (its slug path before a change of a slug or a move) and the category it leads to.
 * Only DoctrineCategoryRedirectRepository uses it; the database deletes it with its category.
 */
#[ORM\Entity]
#[ORM\Table(name: 'catalog_category_redirect')]
#[ORM\Index(name: 'catalog_category_redirect_category_id_idx', columns: ['category_id'])]
class CategoryRedirectRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'old_path', type: 'text')]
        public readonly string $oldPath,
        #[ORM\ManyToOne(targetEntity: CategoryRecord::class)]
        #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public CategoryRecord $category,
    ) {
    }
}
