<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Command\StoredProductImage;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A stored original: its key for the product form and a preview.', required: ['key', 'preview'])]
final readonly class ImageUploadResponse
{
    public function __construct(
        #[OA\Property(description: 'Key of the original (`originals/<sha256 of the content>.<extension of the detected type>`); the same file always gives the same key. Send it as `images[].key` of a product write.', example: 'originals/0a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9.jpg')]
        public string $key,
        #[OA\Property(ref: new Model(type: ImagePreviewResponse::class))]
        public ImagePreviewResponse $preview,
    ) {
    }

    public static function of(StoredProductImage $image): self
    {
        return new self($image->key, ImagePreviewResponse::of($image->preview));
    }
}
