<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

/**
 * One generated person: the name (with Polish letters), the part of a login made of it (`<first>.<last>` without them),
 * the domain of the e-mail address (always one reserved for examples), a Polish mobile phone (+48 and 9 digits, as
 * Ordering stores it) and an address in Poland (street with the number, postal code of the city, city).
 */
final readonly class Person
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public bool $female,
        public string $login,
        public string $emailDomain,
        public string $phone,
        public string $street,
        public string $postalCode,
        public string $city,
    ) {
    }

    public function fullName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    /**
     * `<first>.<last>.<suffix>@<domain>`: the suffix is the number of the account, or `g<n>` for a guest of order n.
     */
    public function email(string $suffix): string
    {
        return $this->login.'.'.$suffix.'@'.$this->emailDomain;
    }
}
