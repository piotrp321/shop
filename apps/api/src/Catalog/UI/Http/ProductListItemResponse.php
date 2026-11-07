<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\BackofficeProductListItem;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A row of the product list of the panel.', required: ['id', 'name', 'slug', 'status', 'mainCategory', 'variantCount', 'image', 'updatedAt'])]
final readonly class ProductListItemResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Buty biegowe Trail')]
        public string $name,
        #[OA\Property(example: 'buty-biegowe-trail')]
        public string $slug,
        #[OA\Property(enum: ['draft', 'published'], description: 'A draft is seen only in the panel.')]
        public string $status,
        #[OA\Property(ref: new Model(type: ProductCategoryResponse::class))]
        public ProductCategoryResponse $mainCategory,
        #[OA\Property(description: 'The number of variants (1 for a product without axes).', example: 3)]
        public int $variantCount,
        #[OA\Property(ref: new Model(type: ImageViewResponse::class), nullable: true, description: 'The main image in the `thumbnail` size; null for a product without images.')]
        public ?ImageViewResponse $image,
        #[OA\Property(description: 'When the product last changed (RFC 3339).', example: '2026-11-01T12:30:00+00:00')]
        public string $updatedAt,
    ) {
    }

    public static function of(BackofficeProductListItem $item): self
    {
        return new self(
            $item->id,
            $item->name,
            $item->slug,
            $item->status,
            ProductCategoryResponse::of($item->mainCategory),
            $item->variantCount,
            null === $item->image ? null : ImageViewResponse::of($item->image),
            $item->updatedAt->format(\DATE_ATOM),
        );
    }
}
