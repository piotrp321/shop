<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * A request class that breaks the rule of RequiredKeysBodyClassesTest: its key `quantity` is typed.
 */
final readonly class IntKeyRequest
{
    public function __construct(
        public mixed $name,
        public int $quantity,
    ) {
    }
}
