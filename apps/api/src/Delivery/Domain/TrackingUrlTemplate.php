<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * The address of the tracking page of the carrier, with `{numer}` in place of the tracking number: `https://` with a
 * host name (letters, digits and dashes in labels joined by dots, an optional port; no user info), at most 500
 * characters once trimmed of Unicode whitespace, without white space or control characters, with `{numer}` exactly
 * once. The checks run in this order, so the first broken rule is the one reported: forbidden characters (on the value
 * as it came), the length, white space inside, the address, the marker.
 */
final readonly class TrackingUrlTemplate
{
    public const int MAX_LENGTH = 500;
    public const string MARKER = '{numer}';

    private const string WHITESPACE = '/[\s\p{Z}]/u';
    private const string LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
    private const string ADDRESS = '~^https://'.self::LABEL.'(?:\.'.self::LABEL.')*(?::[0-9]{1,5})?(?:[/?#].*)?$~Ds';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws InvalidDeliveryMethod also for a text of white space only
     */
    public static function of(string $template): self
    {
        return self::optional($template) ?? throw InvalidDeliveryMethod::templateAddress();
    }

    /**
     * @param string|null $template null and a text of white space only are no template
     *
     * @throws InvalidDeliveryMethod
     */
    public static function optional(?string $template): ?self
    {
        if (null === $template) {
            return null;
        }
        if (DeliveryText::hasForbiddenCharacters($template)) {
            throw InvalidDeliveryMethod::templateCharacters();
        }
        $trimmed = DeliveryText::trim($template);
        if ('' === $trimmed) {
            return null;
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidDeliveryMethod::templateTooLong();
        }
        if (0 !== preg_match(self::WHITESPACE, $trimmed)) {
            throw InvalidDeliveryMethod::templateCharacters();
        }
        if (1 !== preg_match(self::ADDRESS, $trimmed)) {
            throw InvalidDeliveryMethod::templateAddress();
        }
        if (1 !== substr_count($trimmed, self::MARKER)) {
            throw InvalidDeliveryMethod::templateNumber();
        }

        return new self($trimmed);
    }

    /**
     * The tracking address of a shipment: the marker replaced by the number, encoded as a part of a URL.
     */
    public function urlFor(string $trackingNumber): string
    {
        return str_replace(self::MARKER, rawurlencode($trackingNumber), $this->value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
