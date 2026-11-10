<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

/**
 * The argument of a controller marked #[RequiredKeysBody] until RequiredKeysBodyResolver reads the body into `$class`.
 */
final readonly class PendingBody
{
    /**
     * @param class-string $class
     */
    public function __construct(public string $class)
    {
    }
}
