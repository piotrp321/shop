<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ProductEditImage;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'An image of a product in the edit view.', required: ['key', 'alt', 'preview'])]
final readonly class ProductEditImageResponse
{
    public function __construct(
        #[OA\Property(description: 'Key of the original.', example: 'originals/0a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9.jpg')]
        public string $key,
        #[OA\Property(description: 'Alternative text.')]
        public string $alt,
        #[OA\Property(ref: new Model(type: ImageViewResponse::class))]
        public ImageViewResponse $preview,
    ) {
    }

    public static function of(ProductEditImage $image): self
    {
        return new self($image->key, $image->alt, ImageViewResponse::of($image->preview));
    }
}
