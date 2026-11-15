<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use App\Shared\Domain\BoundedCount;
use OpenApi\Attributes as OA;

/**
 * The `total` of the first page of the lists of the panel, as the schema BoundedCount: the rows the filters match,
 * counted up to a limit so that the cost does not grow with the table.
 */
#[OA\Schema(
    description: 'How many rows a list matches. With `capped` the list has more than `value` rows (`value` is the limit of the count, 10 000), otherwise `value` is exact.',
    required: ['value', 'capped'],
)]
final readonly class BoundedCountResponse
{
    public function __construct(
        #[OA\Property(minimum: 0, example: 1234)]
        public int $value,
        #[OA\Property(description: 'True when there are more matching rows than `value`.', example: false)]
        public bool $capped,
    ) {
    }

    public static function of(BoundedCount $count): self
    {
        return new self($count->value, $count->capped);
    }

    public static function ofNullable(?BoundedCount $count): ?self
    {
        return null === $count ? null : self::of($count);
    }
}
