<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryAlreadyExists;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateCategoryHandler
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    /**
     * @throws CategoryAlreadyExists
     * @throws CategoryNotFound      when the parent does not exist
     */
    public function __invoke(CreateCategory $command): void
    {
        $id = CategoryId::fromString($command->id);
        $name = new CategoryName($command->name);

        // save() overwrites a category with the same id, so creation has to refuse an existing one first.
        try {
            $this->categories->get($id);
            throw new CategoryAlreadyExists();
        } catch (CategoryNotFound) {
        }

        $category = null === $command->parentId
            ? Category::createRoot($id, $name)
            : Category::createChild($id, $name, $this->categories->get(CategoryId::fromString($command->parentId)));

        $this->categories->save($category);
    }
}
