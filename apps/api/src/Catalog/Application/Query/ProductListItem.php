<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use OpenApi\Attributes as OA;

final readonly class ProductListItem
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Buty do biegania Puls 2 granatowe')]
        public string $name,
        #[OA\Property(example: 'buty-do-biegania-puls-2-granatowe')]
        public string $slug,
        public string $shortDescription,
        #[OA\Property(description: 'The main image, null when the product has no images.')]
        public ?ImageView $image,
    ) {
    }
}
