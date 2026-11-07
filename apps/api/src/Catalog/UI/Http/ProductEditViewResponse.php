<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ProductEditView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A product as the edit form needs it, with the `version` a save is based on.', required: ['id', 'version', 'status', 'firstPublishedAt', 'name', 'slug', 'slugLocked', 'shortDescription', 'mainCategoryId', 'additionalCategoryIds', 'taxClass', 'images', 'variantAxes', 'variants', 'updatedAt'])]
final readonly class ProductEditViewResponse
{
    /**
     * @param list<string>                     $additionalCategoryIds
     * @param list<ProductEditImageResponse>   $images
     * @param list<string>                     $variantAxes
     * @param list<ProductEditVariantResponse> $variants
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(description: 'Version of the product: send it back with a save, a publication or a withdrawal.', example: 3)]
        public int $version,
        #[OA\Property(enum: ['draft', 'published'])]
        public string $status,
        #[OA\Property(description: 'When the product was first published (RFC 3339); null while it never was.', nullable: true, example: '2026-11-01T12:30:00+00:00')]
        public ?string $firstPublishedAt,
        public string $name,
        public string $slug,
        #[OA\Property(description: 'The slug cannot change: the product was published at least once.')]
        public bool $slugLocked,
        public string $shortDescription,
        #[OA\Property(format: 'uuid')]
        public string $mainCategoryId,
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
        public array $additionalCategoryIds,
        #[OA\Property(example: 'standard')]
        public string $taxClass,
        #[OA\Property(description: 'In presentation order; the first one is the main image.', type: 'array', items: new OA\Items(ref: new Model(type: ProductEditImageResponse::class)))]
        public array $images,
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'), example: ['Kolor', 'Rozmiar'])]
        public array $variantAxes,
        #[OA\Property(description: 'In presentation order.', type: 'array', items: new OA\Items(ref: new Model(type: ProductEditVariantResponse::class)))]
        public array $variants,
        #[OA\Property(description: 'When the product last changed (RFC 3339).', example: '2026-11-01T12:30:00+00:00')]
        public string $updatedAt,
    ) {
    }

    public static function of(ProductEditView $view): self
    {
        return new self(
            $view->id,
            $view->version,
            $view->status,
            $view->firstPublishedAt?->format(\DATE_ATOM),
            $view->name,
            $view->slug,
            $view->slugLocked,
            $view->shortDescription,
            $view->mainCategoryId,
            $view->additionalCategoryIds,
            $view->taxClass,
            array_map(ProductEditImageResponse::of(...), $view->images),
            $view->variantAxes,
            array_map(ProductEditVariantResponse::of(...), $view->variants),
            $view->updatedAt->format(\DATE_ATOM),
        );
    }
}
