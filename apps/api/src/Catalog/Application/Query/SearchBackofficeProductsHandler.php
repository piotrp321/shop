<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\BackofficeProductSource;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Product\ProductStatus;
use App\Shared\Domain\BoundedCount;
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
     * Reads one more product than the page holds: its presence is what tells there is a next page. The first page
     * (no cursor) also carries the bounded total of the filters: the number of its rows when there is no next page
     * (nothing to count), otherwise the source counts, never the table whole.
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
            // The database driver cuts a parameter at a NUL, which would silently search for a different term.
            if (str_contains($query->term, "\0")) {
                throw InvalidBackofficeProductSearch::termWithNul();
            }
        }
        if ($query->perPage < 1 || $query->perPage > SearchBackofficeProducts::MAX_PER_PAGE) {
            throw InvalidBackofficeProductSearch::perPageOutOfRange();
        }

        $status = null;
        if (null !== $query->status) {
            $status = ProductStatus::tryFrom($query->status) ?? throw InvalidBackofficeProductSearch::unknownStatus();
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

        $products = $this->source->page($query->term, $path, $status, $after, $query->perPage + 1);
        // The total of the filters comes with the first page only; the panel keeps it for the next pages.
        if (\count($products) <= $query->perPage) {
            return new BackofficeProductPage($products, null, null === $after ? new BoundedCount(\count($products), false) : null);
        }

        $items = \array_slice($products, 0, $query->perPage);
        $total = null === $after ? $this->source->count($query->term, $path, $status) : null;

        return new BackofficeProductPage($items, ProductCursor::after($items[$query->perPage - 1])->encode(), $total);
    }
}
