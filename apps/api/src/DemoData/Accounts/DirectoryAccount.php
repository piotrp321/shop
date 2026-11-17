<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

/**
 * An account as the directory lists it.
 */
final readonly class DirectoryAccount
{
    public function __construct(
        public string $id,
        public string $username,
        public ?string $email,
        public ?string $firstName,
        public ?string $lastName,
        public bool $enabled,
    ) {
    }
}
