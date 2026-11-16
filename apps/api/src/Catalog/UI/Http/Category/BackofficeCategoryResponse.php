<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use App\Catalog\Application\Query\BackofficeCategoryView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A category as the change saved it.', required: ['id', 'name', 'slug', 'path', 'parentId', 'version'])]
final readonly class BackofficeCategoryResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Namioty')]
        public string $name,
        #[OA\Property(example: 'namioty')]
        public string $slug,
        #[OA\Property(description: 'The address in the store: the slugs from the root joined with `/`.', example: 'turystyka/biwak/namioty')]
        public string $path,
        #[OA\Property(format: 'uuid', description: 'Null for a root.', nullable: true)]
        public ?string $parentId,
        #[OA\Property(description: 'Send it with the next change of the category.', example: 2)]
        public int $version,
    ) {
    }

    public static function of(BackofficeCategoryView $view): self
    {
        return new self($view->id, $view->name, $view->slug, $view->path, $view->parentId, $view->version);
    }
}
