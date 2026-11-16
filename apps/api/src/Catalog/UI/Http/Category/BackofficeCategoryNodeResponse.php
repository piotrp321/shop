<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use App\Catalog\Application\Query\BackofficeCategoryNode;
use App\Shared\UI\Http\BoundedCountResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A node of the tree of the panel, the schema `CategoryNode` of the admin document (alias in nelmio_api_doc.yaml): the
 * node of the store with `version` and `productCount`.
 */
#[OA\Schema(description: 'A category with its descendants, siblings in the order of the store.', required: ['id', 'name', 'slug', 'path', 'version', 'productCount', 'children'])]
final readonly class BackofficeCategoryNodeResponse
{
    /**
     * @param list<BackofficeCategoryNodeResponse> $children
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Namioty')]
        public string $name,
        #[OA\Property(description: 'Unique among the siblings.', example: 'namioty')]
        public string $slug,
        #[OA\Property(description: 'The slugs from the root to the node joined with `/`.', example: 'turystyka/biwak/namioty')]
        public string $path,
        #[OA\Property(description: 'Send it with a change of the category.', example: 1)]
        public int $version,
        #[OA\Property(description: 'The products directly in the category (main or additional, drafts too), up to 1000; null unless asked for with `withCounts=true`.', nullable: true, allOf: [new OA\Schema(ref: new Model(type: BoundedCountResponse::class))])]
        public ?BoundedCountResponse $productCount,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: self::class)))]
        public array $children,
    ) {
    }

    public static function of(BackofficeCategoryNode $node): self
    {
        return new self(
            $node->id,
            $node->name,
            $node->slug,
            $node->path,
            $node->version,
            BoundedCountResponse::ofNullable($node->productCount),
            array_map(self::of(...), $node->children),
        );
    }
}
