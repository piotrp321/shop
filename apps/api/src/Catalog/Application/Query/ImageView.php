<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use OpenApi\Attributes as OA;

/**
 * A product image of one predefined size, with signed URLs of its variants grouped by output format.
 */
final readonly class ImageView
{
    /**
     * @param array<string, list<array{width: int, url: string}>> $variants URLs by output format, widths ascending
     */
    public function __construct(
        #[OA\Property(description: 'Alternative text of the image.', example: 'Biegaczka na leśnej ścieżce')]
        public string $alt,
        #[OA\Property(description: 'Name of the image size.', example: 'listing')]
        public string $size,
        #[OA\Property(description: 'Aspect ratio of every variant, width:height.', example: '1:1')]
        public string $aspectRatio,
        #[OA\Property(
            description: 'Variants by output format (the key is the file extension, e.g. webp), widths ascending.',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(
                    required: ['width', 'url'],
                    properties: [
                        new OA\Property(property: 'width', type: 'integer', example: 256),
                        new OA\Property(property: 'url', type: 'string', example: 'http://localhost:8081/signature/rs:fill:256:256/g:sm/czM6Ly9idWNrZXQva2V5/plain.webp'),
                    ],
                    type: 'object',
                ),
            ),
        )]
        public array $variants,
    ) {
    }
}
