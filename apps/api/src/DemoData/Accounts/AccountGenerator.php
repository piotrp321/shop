<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

use App\DemoData\Identity\DemoUuid;
use App\DemoData\Identity\StaffRoles;
use App\DemoData\Random\Prng;

/**
 * The generated people and accounts (spec: "Klienci", "Pracownicy", "Konta w Keycloak"). A person is a pure function of
 * the seed, the dictionaries and its key (the kind and the number): it draws from a stream of its own (`person:<kind>`,
 * number), so it does not depend on the number of accounts, and an order can ask for the person of one customer.
 *
 * The id of an account is UUID v5 of `customer:<fingerprint>:<n>` or `staff:<fingerprint>:<n>` in the namespace of the
 * seed: the fingerprint changes with the dictionaries of the people and the rule of the roles, so the same id always
 * means the same person and role, and the orders and the history compute it with the same function. Numbers start at 1.
 */
final readonly class AccountGenerator
{
    /** The domains reserved for examples (RFC 2606): no mail of the demo can reach a real person. */
    public const array EMAIL_DOMAINS = ['example.com', 'example.net', 'example.org'];
    /** Prefixes of Polish mobile numbers. */
    private const array MOBILE_PREFIXES = ['50', '51', '53', '57', '60', '66', '69', '72', '73', '78', '79', '88'];
    private const int MAX_BUILDING = 120;
    private const int MAX_FLAT = 80;
    private const float FLAT_SHARE = 0.5;

    public function __construct(
        private string $namespace,
        private PersonDictionaries $people,
    ) {
    }

    public function fingerprint(): string
    {
        return $this->people->fingerprint();
    }

    public function customerId(int $number): string
    {
        return DemoUuid::v5($this->namespace, 'customer:'.$this->fingerprint().':'.self::checked($number));
    }

    public function staffId(int $number): string
    {
        return DemoUuid::v5($this->namespace, 'staff:'.$this->fingerprint().':'.self::checked($number));
    }

    /**
     * A customer of the realm shop: the e-mail address `<first>.<last>.<n>@example.(com|net|org)` is the username.
     */
    public function customer(int $number): GeneratedAccount
    {
        $person = $this->person('customer', $number);
        $email = $person->email((string) $number);

        return new GeneratedAccount($this->customerId($number), $number, $email, $email, $person->firstName, $person->lastName, null, $person);
    }

    /**
     * A staff member of the realm backoffice: the username `<first>.<last>.<n>`, no e-mail address, the role of the number.
     */
    public function staff(int $number): GeneratedAccount
    {
        $person = $this->person('staff', $number);

        return new GeneratedAccount($this->staffId($number), $number, $person->login.'.'.$number, null, $person->firstName, $person->lastName, StaffRoles::roleOf($number), $person);
    }

    /**
     * The person of a key: `customer` and `staff` for the accounts, `guest` for the guests of the orders.
     */
    public function person(string $kind, int $number): Person
    {
        $prng = Prng::forPart($this->namespace, 'person:'.$kind, self::checked($number));

        $female = $prng->chance($this->people->femaleShare());
        $firstName = $this->people->firstNames($female)->pick($prng);
        $lastName = $this->people->surnames($female)->pick($prng);
        $domain = $prng->pick(self::EMAIL_DOMAINS);
        $phone = '+48'.$prng->pick(self::MOBILE_PREFIXES).str_pad((string) $prng->int(0, 9_999_999), 7, '0', \STR_PAD_LEFT);

        $city = $this->people->cities()[$this->people->cityWeights()->pickIndex($prng)];
        $postalCode = $prng->pick($city['postalCodes']);
        $street = $prng->pick($this->people->streets());
        // Low building numbers are the likelier ones; about half of the addresses have a flat.
        $building = min($prng->int(1, self::MAX_BUILDING), $prng->int(1, self::MAX_BUILDING));
        $flat = $prng->int(1, self::MAX_FLAT);
        $address = $street.' '.$building.($prng->chance(self::FLAT_SHARE) ? '/'.$flat : '');

        return new Person(
            $firstName,
            $lastName,
            $female,
            Transliteration::login($firstName).'.'.Transliteration::login($lastName),
            $domain,
            $phone,
            $address,
            $postalCode,
            $city['name'],
        );
    }

    private static function checked(int $number): int
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('The number of a generated account or person starts at 1.');
        }

        return $number;
    }
}
