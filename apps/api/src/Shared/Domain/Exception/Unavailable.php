<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * A dependency of the request cannot be reached or did not answer properly (e.g. a payment provider), or the
 * operation lost a race that a retry resolves. The HTTP layer renders it as a 503 like any server error, without
 * `detail`: the client can only try again later.
 */
interface Unavailable extends ProblemException
{
}
