<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * The request conflicts with the current state, e.g. a taken unique value.
 */
interface Conflict extends ProblemException
{
}
