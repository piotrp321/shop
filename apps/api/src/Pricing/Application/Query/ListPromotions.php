<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Domain\Promotion\PromotionStatus;

/**
 * A page of the promotions of the panel, newest start first.
 */
final readonly class ListPromotions
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE = 100;

    /**
     * @param PromotionStatus|null $status only the promotions in this status now
     * @param string|null          $after  the `next` of the previous page
     */
    public function __construct(
        public ?PromotionStatus $status,
        public ?string $after,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }
}
