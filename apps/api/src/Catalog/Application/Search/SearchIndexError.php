<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * A permanent search failure: a rejected request or mapping, a missing alias, or indexes in an unexpected state.
 * A programming or configuration error; retrying will not help.
 */
final class SearchIndexError extends \RuntimeException
{
}
