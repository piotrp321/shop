<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The orders of the customer: all, the paid ones and their gross value (refunds not taken off), the first and the last one. Amounts in grosze, moments RFC 3339 in UTC.', required: ['orderCount', 'paidCount', 'paidGross', 'currency', 'firstOrderAt', 'lastOrderAt'])]
final readonly class BackofficeCustomerSummaryResponse
{
    public function __construct(
        #[OA\Property(minimum: 1, example: 3)]
        public int $orderCount,
        #[OA\Property(minimum: 0, example: 2)]
        public int $paidCount,
        #[OA\Property(minimum: 0, example: 24600)]
        public int $paidGross,
        #[OA\Property(example: 'PLN')]
        public string $currency,
        #[OA\Property(format: 'date-time', example: '2026-10-01T10:00:00+00:00')]
        public string $firstOrderAt,
        #[OA\Property(format: 'date-time', example: '2026-11-05T12:30:00+00:00')]
        public string $lastOrderAt,
    ) {
    }
}
