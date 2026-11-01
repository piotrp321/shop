<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\GetPriceHistory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of GET /api/v1/admin/products/{productId}/price-history. The violation messages never contain the
 * submitted value.
 */
final readonly class PriceHistoryQuery
{
    /** Longer than any cursor the API hands out (a time and a UUID in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;

    public function __construct(
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: GetPriceHistory::MAX_PER_PAGE)]
        public int $perPage = GetPriceHistory::DEFAULT_PER_PAGE,
    ) {
    }
}
