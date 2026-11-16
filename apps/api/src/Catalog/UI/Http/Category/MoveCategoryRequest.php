<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of POST /api/v1/admin/categories/{id}/move: the parent and the place among its children, and the version the
 * move is based on. The same parent is a new order. Strict types; every key is required.
 */
#[OA\Schema(description: 'Where the category (with its subcategories) goes: under a parent (null: among the roots), at a position among its children.', required: ['parentId', 'position', 'version'])]
final readonly class MoveCategoryRequest
{
    public function __construct(
        #[OA\Property(type: 'string', format: 'uuid', description: 'The new parent, the current one for a new order; null for a root.', nullable: true)]
        public mixed $parentId,
        #[OA\Property(type: 'integer', description: 'The place among the children of the parent, without the category itself: 0 is the first; a position past the last child is the end.', minimum: 0, example: 0)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: CategoryBodyRules::POSITION),
            new Assert\Type('integer', message: CategoryBodyRules::POSITION),
            new Assert\PositiveOrZero(message: CategoryBodyRules::POSITION),
        ])]
        public mixed $position,
        #[OA\Property(type: 'integer', description: 'The `version` of the category the move is based on.', minimum: 1, example: 1)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: CategoryBodyRules::VERSION),
            new Assert\Type('integer', message: CategoryBodyRules::VERSION),
            new Assert\Positive(message: CategoryBodyRules::VERSION),
        ])]
        public mixed $version,
    ) {
    }

    #[Assert\Callback]
    public function validateValues(ExecutionContextInterface $context): void
    {
        CategoryBodyRules::validateParent($context, $this->parentId);
    }
}
