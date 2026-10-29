<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * A transient search failure: no connection, a timeout, an overloaded cluster (429, 502, 503, 504, rejected execution).
 * Retrying later may succeed.
 */
final class SearchUnavailable extends \RuntimeException
{
}
