<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ImageView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'An image in the `thumbnail` size with signed URLs of its variants.', required: ['alt', 'size', 'aspectRatio', 'variants'])]
final readonly class ImageViewResponse
{
    /**
     * @param array<string, list<array{width: int, url: string}>> $variants
     */
    public function __construct(
        #[OA\Property(description: 'Alternative text.')]
        public string $alt,
        #[OA\Property(description: 'The image size.', example: 'thumbnail')]
        public string $size,
        #[OA\Property(description: 'Width to height of every variant.', example: '1:1')]
        public string $aspectRatio,
        #[OA\Property(description: 'Signed URLs by output format (the file extension), widths ascending.', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'object', required: ['width', 'url'], properties: [
            new OA\Property(property: 'width', type: 'integer', example: 96),
            new OA\Property(property: 'url', type: 'string', format: 'uri'),
        ])))]
        public array $variants,
    ) {
    }

    public static function of(ImageView $image): self
    {
        return new self($image->alt, $image->size, $image->aspectRatio, $image->variants);
    }
}
