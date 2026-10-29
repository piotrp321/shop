<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

enum IndexOutcome
{
    case Indexed;
    /** The index already holds this version of the product or a newer one; nothing was written. */
    case Stale;
}
