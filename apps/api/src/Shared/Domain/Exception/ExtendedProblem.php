<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * A {@see ProblemException} that carries extension members of its RFC 9457 document, data a client acts on (e.g. the
 * ids of the variants in conflict), next to the `type` and `detail`. Like the message, the values must not contain
 * internal data. The members of the problem itself (`type`, `title`, `status`, `detail`, `instance`, `violations`) are
 * never replaced, and a 5xx has no extensions.
 */
interface ExtendedProblem extends ProblemException
{
    /**
     * @return array<string, mixed> member name => JSON value
     */
    public function problemExtensions(): array;
}
