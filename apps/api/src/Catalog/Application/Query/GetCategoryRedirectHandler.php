<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Domain\Category\CategoryRedirectRepository;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Category\CategoryTree;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCategoryRedirectHandler
{
    public function __construct(private CategoryRedirectRepository $redirects, private CategoryRepository $categories)
    {
    }

    /**
     * @return string|null the current address of the category the old one leads to; null when it leads nowhere (also
     *                     the current address of a category, and an address of a deleted one)
     */
    public function __invoke(GetCategoryRedirect $query): ?string
    {
        $categoryId = $this->redirects->find($query->path);
        if (null === $categoryId) {
            return null;
        }

        return CategoryTree::of($this->categories->all())->slugPath($categoryId)->toString();
    }
}
