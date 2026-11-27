<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * What the setup of the search alias found; it never lists a failure, those are exceptions.
 */
final readonly class SetupResult
{
    /**
     * @param list<string> $orphans       indexes of the alias the alias does not point to; never deleted by the setup
     * @param string|null  $outdatedIndex the index the alias points to when it was built from another index definition
     *                                    than the current one (left as it is until catalog:reindex replaces it)
     */
    public function __construct(
        public array $orphans = [],
        public ?string $outdatedIndex = null,
    ) {
    }
}
