<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Cache;

/**
 * A purge or a revalidation did not succeed: a status other than 200, a network failure or a timeout. The message
 * names the layer and the cause, never a secret.
 */
final class StorefrontCacheFailure extends \RuntimeException
{
}
