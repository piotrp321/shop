<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Text;

/**
 * Where an order goes, copied into it: a street with its number (1 to 100 characters once trimmed), a postal code
 * NN-NNN and a city (1 to 60 characters) in Poland, the only country. Street and city are texts by the rule of texts
 * (Text: no forbidden character, trimmed ends, at least one visible character). The postal code has no forbidden
 * character in the value as it came and is checked by its format once trimmed by the rule.
 */
final readonly class ShippingAddress
{
    public const int MAX_STREET_LENGTH = 100;
    public const int MAX_CITY_LENGTH = 60;
    public const string POLAND = 'PL';
    private const string POSTAL_CODE_PATTERN = '/^[0-9]{2}-[0-9]{3}$/D';

    private function __construct(
        private string $street,
        private string $postalCode,
        private string $city,
    ) {
    }

    /**
     * @throws InvalidOrderInput
     */
    public static function inPoland(string $street, string $postalCode, string $city): self
    {
        $trimmedStreet = Text::normalize($street);
        if ('' === $trimmedStreet) {
            throw InvalidOrderInput::streetMissing();
        }
        if (mb_strlen($trimmedStreet) > self::MAX_STREET_LENGTH) {
            throw InvalidOrderInput::streetTooLong();
        }
        if (Text::hasForbiddenCharacters($street)) {
            throw InvalidOrderInput::streetCharacters();
        }
        if (Text::hasForbiddenCharacters($postalCode)) {
            throw InvalidOrderInput::postalCode();
        }
        $trimmedPostalCode = Text::normalize($postalCode);
        if ('' === $trimmedPostalCode) {
            throw InvalidOrderInput::postalCodeMissing();
        }
        if (1 !== preg_match(self::POSTAL_CODE_PATTERN, $trimmedPostalCode)) {
            throw InvalidOrderInput::postalCode();
        }
        $trimmedCity = Text::normalize($city);
        if ('' === $trimmedCity) {
            throw InvalidOrderInput::cityMissing();
        }
        if (mb_strlen($trimmedCity) > self::MAX_CITY_LENGTH) {
            throw InvalidOrderInput::cityTooLong();
        }
        if (Text::hasForbiddenCharacters($city)) {
            throw InvalidOrderInput::cityCharacters();
        }

        return new self($trimmedStreet, $trimmedPostalCode, $trimmedCity);
    }

    /**
     * An address as stored, without the rules: an order stored before a stricter rule is still read and processed
     * (spec api-input-contract, E7); only a placement goes through inPoland().
     */
    public static function stored(string $street, string $postalCode, string $city): self
    {
        return new self($street, $postalCode, $city);
    }

    public function street(): string
    {
        return $this->street;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function country(): string
    {
        return self::POLAND;
    }
}
