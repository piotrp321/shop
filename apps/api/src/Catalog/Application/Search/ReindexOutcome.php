<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

enum ReindexOutcome
{
    case Indexed;
    /** The index already holds the document of a newer read; nothing was written. */
    case Stale;
    /** The product is not in the database; nothing was written. */
    case ProductNotFound;
}
