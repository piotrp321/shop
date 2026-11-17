<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Category\CategoryId;

/**
 * The products of a category subtree as the search index has them: every document with the category among its
 * categories or their ancestors (main and additional, drafts too), in the order of their ids. A document of the
 * subtree keeps the category there before and after a rename or a move of it, so paging by the id reaches each once.
 */
interface SubtreeProductIds
{
    /**
     * @param string|null $afterId the last id of the previous page; null for the first
     *
     * @return list<string> at most $limit ids, ascending, after $afterId
     *
     * @throws SearchUnavailable
     * @throws SearchIndexError
     */
    public function after(CategoryId $categoryId, ?string $afterId, int $limit): array;
}
