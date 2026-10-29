<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * Outcome of switching the alias to a new index, read back from Elasticsearch.
 */
enum ActivationResult
{
    /** The alias points exactly to the new index. */
    case Activated;
    /** The alias still points to the previous index (or the switch was never sent). */
    case NotActivated;
    /** The alias state could not be read or is neither of the two; nothing may be deleted. */
    case Unknown;
}
