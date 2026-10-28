<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * The input is well formed but violates a business rule.
 */
interface InvalidInput extends ProblemException
{
}
