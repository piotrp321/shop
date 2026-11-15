<?php

declare(strict_types=1);

namespace App\Inventory\UI\Http;

use App\Inventory\Application\Query\ListBackofficeStock;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Query string of GET /api/v1/admin/stock. The violation messages never contain the submitted value.
 */
final readonly class BackofficeStockQuery
{
    /** Longer than any cursor the API hands out (a name of 200 characters in JSON and base64url). */
    public const int MAX_CURSOR_LENGTH = 2048;
    /** The values of ProductSort of Catalog, which the list pages through. */
    public const array SORTS = ['name', '-name', '-updatedAt', 'updatedAt'];
    /** The levels the list is filtered by (the values of AvailabilityLevel but the one of an ample stock). */
    public const array LEVELS = ['out_of_stock', 'low_stock'];
    public const string BAD_LEVEL = 'Poziom stanu musi być jednym z: out_of_stock, low_stock.';
    public const string LEVEL_EXCLUDES = 'Filtr poziomu nie łączy się z wyszukiwaniem, kategorią ani sortowaniem.';

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
        #[Assert\Range(min: 1, max: ListBackofficeStock::MAX_PER_PAGE)]
        public int $perPage = ListBackofficeStock::DEFAULT_PER_PAGE,
        // A string checked by validateLevel: a value of another kind (`level[]=…`) gets the same Polish message.
        #[OA\Property(type: 'string', enum: self::LEVELS, nullable: true)]
        public mixed $level = null,
    ) {
    }

    /**
     * The level is one of LEVELS.
     */
    #[Assert\Callback]
    public function validateLevel(ExecutionContextInterface $context): void
    {
        if (null !== $this->level && !\in_array($this->level, self::LEVELS, true)) {
            $context->buildViolation(self::BAD_LEVEL)->atPath('level')->addViolation();
        }
    }

    /**
     * The level is paged by the stock records alone: a search, a category and a sort have no index to go with it. The
     * violation is on each of the fields to drop.
     */
    #[Assert\Callback]
    public function validateLevelWithFilters(ExecutionContextInterface $context): void
    {
        if (null === $this->level) {
            return;
        }
        foreach (['q' => $this->q, 'category' => $this->category, 'sort' => $this->sort] as $path => $value) {
            if (null !== $value) {
                $context->buildViolation(self::LEVEL_EXCLUDES)->atPath($path)->addViolation();
            }
        }
    }
}
