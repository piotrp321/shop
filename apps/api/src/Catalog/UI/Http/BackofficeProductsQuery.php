<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\Application\Query\ListBackofficeProducts;
use App\Catalog\Application\Query\SearchBackofficeProducts;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of GET /api/v1/admin/products. The violation messages never contain the submitted value.
 */
final readonly class BackofficeProductsQuery
{
    /** Longer than any cursor the API hands out (a name of 200 characters in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;

    public function __construct(
        #[Assert\Length(min: SearchBackofficeProducts::MIN_TERM_LENGTH, max: SearchBackofficeProducts::MAX_TERM_LENGTH)]
        // PostgreSQL cannot take a NUL in a parameter: the driver would cut the term there and search for something else.
        #[Assert\Regex(pattern: '/\0/', message: 'The search term must not contain a NUL character.', match: false)]
        public ?string $q = null,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Uuid]
        public ?string $category = null,
        #[Assert\Choice(choices: ['draft', 'published'], message: 'The status must be draft or published.')]
        public ?string $status = null,
        #[Assert\Length(min: 1, max: self::MAX_CURSOR_LENGTH)]
        public ?string $after = null,
        #[Assert\Range(min: 1, max: SearchBackofficeProducts::MAX_PER_PAGE)]
        public int $perPage = ListBackofficeProducts::DEFAULT_PER_PAGE,
    ) {
    }
}
