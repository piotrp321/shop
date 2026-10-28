<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Marks an exception of this project whose message is safe to show to API clients.
 *
 * Only exceptions implementing this interface get their message in the `detail` of a problem details response.
 * Framework and library exceptions never do. The message must not contain internal data.
 */
interface ProblemException extends \Throwable
{
    /**
     * HTTP status of the problem details response, e.g. 404 or 409.
     */
    public function problemStatus(): int;
}
