<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * @param int $count the number of products the search would return with the value added to the filter
 */
final readonly class OptionValue
{
    public function __construct(public string $value, public int $count)
    {
    }
}
