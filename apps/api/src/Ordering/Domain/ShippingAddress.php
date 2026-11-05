<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Where an order goes, copied into it: a street with its number (1 to 100 characters after trimming), a postal code
 * NN-NNN and a city (1 to 60 characters) in Poland, the only country. Street and city have no control character.
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
        [$rawStreet, $rawCity] = [$street, $city];
        $street = trim($street);
        $postalCode = trim($postalCode);
        $city = trim($city);
        if ('' === $street) {
            throw InvalidOrderInput::streetMissing();
        }
        if (mb_strlen($street) > self::MAX_STREET_LENGTH) {
            throw InvalidOrderInput::streetTooLong();
        }
        if (ControlCharacters::in($rawStreet)) {
            throw InvalidOrderInput::streetCharacters();
        }
        if ('' === $postalCode) {
            throw InvalidOrderInput::postalCodeMissing();
        }
        if (1 !== preg_match(self::POSTAL_CODE_PATTERN, $postalCode)) {
            throw InvalidOrderInput::postalCode();
        }
        if ('' === $city) {
            throw InvalidOrderInput::cityMissing();
        }
        if (mb_strlen($city) > self::MAX_CITY_LENGTH) {
            throw InvalidOrderInput::cityTooLong();
        }
        if (ControlCharacters::in($rawCity)) {
            throw InvalidOrderInput::cityCharacters();
        }

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
