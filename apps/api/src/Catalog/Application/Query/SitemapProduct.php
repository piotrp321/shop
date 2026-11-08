<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use OpenApi\Attributes as OA;

final readonly class SitemapProduct
{
    /**
     * @param string $updatedAt time of the last change of the product, ISO 8601 with the offset
     */
    public function __construct(
        public string $slug,
        #[OA\Property(format: 'date-time', example: '2026-11-20T23:00:00+00:00')]
        public string $updatedAt,
    ) {
    }
}
