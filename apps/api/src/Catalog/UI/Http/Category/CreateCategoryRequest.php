<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use App\Catalog\Domain\Category\CategoryName;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of POST /api/v1/admin/categories. Strict types; every key is required (`parentId` null for a root).
 */
#[OA\Schema(description: 'A new category, placed at the end of its siblings.', required: ['name', 'slug', 'parentId'])]
final readonly class CreateCategoryRequest
{
    public function __construct(
        #[OA\Property(type: 'string', description: '1 to 100 characters once trimmed of white space and invisible characters at the ends, with a visible character, without control characters or line separators.', maxLength: CategoryName::MAX_LENGTH, example: 'Namioty')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: CategoryBodyRules::NAME),
            new Assert\Type('string', message: CategoryBodyRules::NAME_TYPE),
        ])]
        public mixed $name,
        #[OA\Property(type: 'string', description: 'The address segment: lower case a-z and digits in words joined by single dashes, 1 to 60 characters, unique among the siblings.', maxLength: 60, pattern: '^[a-z0-9]+(-[a-z0-9]+)*$', example: 'namioty')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: CategoryBodyRules::SLUG),
            new Assert\Type('string', message: CategoryBodyRules::SLUG_TYPE),
        ])]
        public mixed $slug,
        #[OA\Property(type: 'string', format: 'uuid', description: 'The parent category; null for a root.', nullable: true)]
        public mixed $parentId,
    ) {
    }

    #[Assert\Callback]
    public function validateValues(ExecutionContextInterface $context): void
    {
        CategoryBodyRules::validateNameAndSlug($context, $this->name, $this->slug);
        CategoryBodyRules::validateParent($context, $this->parentId);
    }
}
