<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Lines that no order may have (none, more than 50, a variant twice, a quantity outside 1 to 99, a name that is not
 * a name). A logic error: the handler builds the lines from a cart, which keeps these limits.
 */
final class InvalidOrder extends \LogicException
{
}
