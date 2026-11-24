<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Text;

/**
 * Who to contact about an order: the email address, the full name and a Polish phone number. The full name is a text
 * by the rule of texts (Text: no forbidden character, trimmed ends, at least one visible character), 1 to 100
 * characters once trimmed. The phone has no forbidden character in the value as it came; once trimmed by the rule,
 * spaces and hyphens are dropped and it is stored as +48 and 9 digits (+48 is optional).
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
        $trimmed = Text::normalize($fullName);
        $length = mb_strlen($trimmed);
        if (0 === $length) {
            throw InvalidOrderInput::fullNameMissing();
        }
        if ($length > self::MAX_FULL_NAME_LENGTH) {
            throw InvalidOrderInput::fullNameTooLong();
        }
        if (Text::hasForbiddenCharacters($fullName)) {
            throw InvalidOrderInput::fullNameCharacters();
        }

        return new self($email, $trimmed, self::normalisedPhone($phone));
    }

    /**
     * A contact as stored, without the rules: an order stored before a stricter rule is still read and processed (spec
     * api-input-contract, E7); only a placement goes through of().
     */
    public static function stored(EmailAddress $email, string $fullName, string $phone): self
    {
        return new self($email, $fullName, $phone);
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
        if (Text::hasForbiddenCharacters($phone) || 1 !== preg_match(self::PHONE_PATTERN, str_replace([' ', '-'], '', Text::normalize($phone)), $matches)) {
            throw InvalidOrderInput::phone();
        }

        return '+48'.$matches[1];
    }
}
