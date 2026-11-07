<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

enum IndexOutcome
{
    case Indexed;
    /** The index already holds a newer version of the product; nothing was written. */
    case Stale;
}
