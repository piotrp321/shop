<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'The number of products as the statistics of the database estimate it; the products are never counted for the dashboard.',
    required: ['value', 'estimated'],
)]
final readonly class DashboardProductCountResponse
{
    public function __construct(
        #[OA\Property(minimum: 0, example: 10000000)]
        public int $value,
        #[OA\Property(description: 'Always true: the number is an estimate.', example: true)]
        public bool $estimated = true,
    ) {
    }
}
