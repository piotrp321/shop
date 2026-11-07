<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Application\Query\ListPromotions;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of GET /api/v1/admin/promotions. The violation messages never contain the submitted value.
 */
final readonly class PromotionListQuery
{
    /** The values of PromotionStatus. */
    public const array STATUSES = ['planned', 'active', 'ended'];
    /** Longer than any cursor the API hands out (a moment and a UUID in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;

    public function __construct(
        #[Assert\Choice(choices: self::STATUSES, message: 'The status is not one of the statuses of a promotion.')]
        public ?string $status = null,
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: ListPromotions::MAX_PER_PAGE)]
        public int $perPage = ListPromotions::DEFAULT_PER_PAGE,
    ) {
    }
}
