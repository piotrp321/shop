<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Who to contact about an order: the email address, the full name (1 to 100 characters after trimming, no control
 * character) and a Polish
 * phone number, stored as +48 and 9 digits (spaces and hyphens are dropped, +48 is optional).
 */
final readonly class Contact
{
    public const int MAX_FULL_NAME_LENGTH = 100;
    private const string PHONE_PATTERN = '/^(?:\+48)?([0-9]{9})$/D';

    private function __construct(
        private EmailAddress $email,
        private string $fullName,
        private string $phone,
    ) {
    }

    /**
     * @throws InvalidOrderInput
     */
    public static function of(EmailAddress $email, string $fullName, string $phone): self
    {
        $raw = $fullName;
        $fullName = trim($fullName);
        $length = mb_strlen($fullName);
        if (0 === $length) {
            throw InvalidOrderInput::fullNameMissing();
        }
        if ($length > self::MAX_FULL_NAME_LENGTH) {
            throw InvalidOrderInput::fullNameTooLong();
        }
        if (ControlCharacters::in($raw)) {
            throw InvalidOrderInput::fullNameCharacters();
        }

        return new self($email, $fullName, self::normalisedPhone($phone));
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    public function fullName(): string
    {
        return $this->fullName;
    }

    /**
     * +48 and 9 digits.
     */
    public function phone(): string
    {
        return $this->phone;
    }

    /**
     * @throws InvalidOrderInput
     */
    private static function normalisedPhone(string $phone): string
    {
        if (1 !== preg_match(self::PHONE_PATTERN, str_replace([' ', '-'], '', $phone), $matches)) {
            throw InvalidOrderInput::phone();
        }

        return '+48'.$matches[1];
    }
}
