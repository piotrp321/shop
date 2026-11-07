<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * The offers of the products could not be read (e.g. a lost database connection); the cause is the previous
 * exception. Retrying later may succeed.
 */
final class OfferFactsUnavailable extends \RuntimeException
{
}
