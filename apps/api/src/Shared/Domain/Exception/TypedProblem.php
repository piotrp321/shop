<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * A {@see ProblemException} that has a stable RFC 9457 `type`, so that a client can tell its cause from other problems
 * of the same status without reading the `detail`.
 *
 * The type is an absolute URI that identifies the cause forever (this project uses `tag:` URIs and does not serve
 * them). Exceptions that do not implement this interface are rendered with `about:blank`.
 */
interface TypedProblem extends ProblemException
{
    public function problemType(): string;
}
