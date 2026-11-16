<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The current address of the category an old address leads to.', required: ['path'])]
final readonly class CategoryRedirectResponse
{
    public function __construct(
        #[OA\Property(description: 'The slugs from the root to the category joined with `/`, without slashes at the ends.', example: 'turystyka/biwak/namioty')]
        public string $path,
    ) {
    }
}
