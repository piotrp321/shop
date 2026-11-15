<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use App\Pricing\Application\Query\ListBackofficePrices;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of GET /api/v1/admin/prices. The violation messages never contain the submitted value.
 */
final readonly class BackofficePricesQuery
{
    /** Longer than any cursor the API hands out (a name of 200 characters in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;
    /** The values of ProductSort of Catalog, which the list pages through. */
    public const array SORTS = ['name', '-name', '-updatedAt', 'updatedAt'];

    public function __construct(
        #[Assert\Length(min: 2, max: 100)]
        // PostgreSQL cannot take a NUL in a parameter: the driver would cut the term there and search for something else.
        #[Assert\Regex(pattern: '/\0/', message: 'The search term must not contain a NUL character.', match: false)]
        public ?string $q = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Uuid]
        public ?string $category = null,
        #[Assert\Choice(choices: self::SORTS, message: 'The sort must be one of name, -name, -updatedAt, updatedAt.')]
        public ?string $sort = null,
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: ListBackofficePrices::MAX_PER_PAGE)]
        public int $perPage = ListBackofficePrices::DEFAULT_PER_PAGE,
    ) {
    }
}
