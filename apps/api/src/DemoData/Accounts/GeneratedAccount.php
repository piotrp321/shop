<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

/**
 * An account of the generator: a customer of the realm shop (the e-mail address is the username, no role) or a staff
 * member of the realm backoffice (`<first>.<last>.<n>`, no e-mail address, the role of the number).
 */
final readonly class GeneratedAccount
{
    public function __construct(
        public string $id,
        public int $number,
        public string $username,
        public ?string $email,
        public string $firstName,
        public string $lastName,
        public ?string $role,
        public Person $person,
    ) {
    }
}
