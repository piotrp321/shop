<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;

/**
 * A page of the discount codes of the panel, newest first.
 */
final readonly class ListDiscountCodes
{
    public const int DEFAULT_PER_PAGE = 25;
    public const int MAX_PER_PAGE = 100;

    /**
     * @param DiscountCodeStatus|null $status only the codes in this status now
     * @param string|null             $q      the beginning of the text of the code, in any case
     * @param string|null             $after  the `next` of the previous page
     */
    public function __construct(
        public ?DiscountCodeStatus $status,
        public ?string $q,
        public ?string $after,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }
}
