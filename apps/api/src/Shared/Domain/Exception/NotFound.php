<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * The requested thing does not exist.
 */
interface NotFound extends ProblemException
{
}
