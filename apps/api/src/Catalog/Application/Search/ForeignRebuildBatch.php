<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * A batch that the producer of the run never sent: an unknown number, or ids other than those it sent under that
 * number. It is never counted.
 */
final class ForeignRebuildBatch extends \InvalidArgumentException
{
}
