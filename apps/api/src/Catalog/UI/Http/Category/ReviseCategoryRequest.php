<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use App\Catalog\Domain\Category\CategoryName;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body of PUT /api/v1/admin/categories/{id}: the name and the slug as they should be, and the version they are based
 * on. Strict types; every key is required.
 */
#[OA\Schema(description: 'The name and the slug of a category. A new slug changes the address of the category and its descendants; the old addresses redirect to the new ones.', required: ['name', 'slug', 'version'])]
final readonly class ReviseCategoryRequest
{
    public function __construct(
        #[OA\Property(type: 'string', description: '1 to 100 characters once trimmed of whitespace, without control characters.', maxLength: CategoryName::MAX_LENGTH, example: 'Namioty')]
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
        #[OA\Property(type: 'integer', description: 'The `version` of the category the change is based on.', minimum: 1, example: 1)]
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
        CategoryBodyRules::validateNameAndSlug($context, $this->name, $this->slug);
    }
}
