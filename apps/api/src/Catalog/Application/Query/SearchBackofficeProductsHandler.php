<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class SearchBackofficeProductsHandler
{
    public function __construct(
        private BackofficeProductSource $source,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * Reads one more product than the page holds: its presence is the only thing that tells there is a next page, so
     * the whole result is never counted.
     *
     * @throws InvalidBackofficeProductSearch
     * @throws InvalidProductCursor
     * @throws CategoryNotFound
     */
    public function __invoke(SearchBackofficeProducts $query): BackofficeProductPage
    {
        if (null !== $query->term) {
            $length = mb_strlen($query->term);
            if ($length < SearchBackofficeProducts::MIN_TERM_LENGTH || $length > SearchBackofficeProducts::MAX_TERM_LENGTH) {
                throw InvalidBackofficeProductSearch::termLengthOutOfRange();
            }
        }
        if ($query->perPage < 1 || $query->perPage > SearchBackofficeProducts::MAX_PER_PAGE) {
            throw InvalidBackofficeProductSearch::perPageOutOfRange();
        }

        $path = null;
        if (null !== $query->categoryId) {
            try {
                $categoryId = CategoryId::fromString($query->categoryId);
            } catch (\InvalidArgumentException) {
                throw InvalidBackofficeProductSearch::notACategoryId();
            }
            $path = $this->categories->get($categoryId)->path();
        }
        $after = null === $query->after ? null : ProductCursor::decode($query->after);

        $products = $this->source->page($query->term, $path, $after, $query->perPage + 1);
        if (\count($products) <= $query->perPage) {
            return new BackofficeProductPage($products, null);
        }

        $items = \array_slice($products, 0, $query->perPage);

        return new BackofficeProductPage($items, ProductCursor::after($items[$query->perPage - 1])->encode());
    }
}
