<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marks an exception of this project whose message is safe to show to API clients.
 *
 * Only exceptions implementing this interface get their message in the `detail` of a problem details response.
 * Framework and library exceptions never do. The message must not contain internal data or user input.
 *
 * The interface carries no transport details. Sub-interfaces say what kind of problem it is
 * ({@see NotFound}, {@see Conflict}, {@see InvalidInput}), the HTTP layer maps the kind to a status.
 * A bare ProblemException is treated as invalid input.
 */
interface ProblemException extends \Throwable
{
}
