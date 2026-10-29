<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of GET /api/v1/products. The violation messages never contain the submitted value.
 */
final readonly class ListProductsQuery
{
    public function __construct(
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Uuid]
        public ?string $category = null,
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 48)]
        public int $perPage = 24,
    ) {
    }
}
