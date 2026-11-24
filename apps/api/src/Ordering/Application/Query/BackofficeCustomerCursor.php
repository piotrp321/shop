<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Application\Port\ListedCustomer;
use App\Ordering\Domain\ControlCharacters;
use App\Ordering\Domain\OrderOwner;

/**
 * The position after a customer in a sort of the customer list of the panel: the value of the sorted column (the
 * moment of the last order, the name as the sort compares it, the number of orders or the paid value) and the customer
 * id. Handed out as opaque text, base64url without padding of a JSON object `{"s": the key of the sort, "v": the value,
 * "i": the customer id}`; a cursor of one sort is no cursor of another.
 */
final readonly class BackofficeCustomerCursor
{
    private const int MAX_TEXT_LENGTH = 2048;
    /** UTC with microseconds, so a cursor keeps the whole precision of the stored moment. */
    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';
    private const string TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/D';
    /** Longer than any lower(full_name) of a name of 100 characters. */
    private const int MAX_NAME_LENGTH = 400;

    /**
     * @param \DateTimeImmutable|int|string $value the moment of the last order, the number of orders, the paid value in
     *                                             grosze or the name in lower case
     */
    private function __construct(
        public BackofficeCustomerSort $sort,
        public \DateTimeImmutable|int|string $value,
        public string $customerId,
    ) {
    }

    public static function after(ListedCustomer $customer, BackofficeCustomerSort $sort): self
    {
        $value = match ($sort) {
            BackofficeCustomerSort::LastOrderDescending => $customer->lastOrderAt,
            BackofficeCustomerSort::Name => $customer->sortName,
            BackofficeCustomerSort::OrderCountDescending => $customer->orderCount,
            BackofficeCustomerSort::PaidGrossDescending => $customer->paidGross,
        };

        return new self($sort, $value, $customer->customerId);
    }

    /**
     * @throws InvalidBackofficeCustomerCursor also for a cursor of another sort
     */
    public static function decode(string $text, BackofficeCustomerSort $sort): self
    {
        if ('' === $text || \strlen($text) > self::MAX_TEXT_LENGTH || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new InvalidBackofficeCustomerCursor();
        }
        $json = base64_decode(strtr($text, '-_', '+/'), true);
        if (false === $json) {
            throw new InvalidBackofficeCustomerCursor();
        }
        try {
            $data = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidBackofficeCustomerCursor();
        }
        if (!\is_array($data) || 3 !== \count($data) || !isset($data['s'], $data['v'], $data['i']) || $sort->value !== $data['s'] || !self::isText($data['i'], OrderOwner::MAX_CUSTOMER_ID_LENGTH)) {
            throw new InvalidBackofficeCustomerCursor();
        }

        $value = match ($sort) {
            BackofficeCustomerSort::LastOrderDescending => self::decodeMoment($data['v']),
            BackofficeCustomerSort::Name => self::isName($data['v']) ? $data['v'] : throw new InvalidBackofficeCustomerCursor(),
            BackofficeCustomerSort::OrderCountDescending, BackofficeCustomerSort::PaidGrossDescending => \is_int($data['v']) && $data['v'] >= 0 ? $data['v'] : throw new InvalidBackofficeCustomerCursor(),
        };

        return new self($sort, $value, $data['i']);
    }

    public function encode(): string
    {
        $data = [
            's' => $this->sort->value,
            'v' => $this->value instanceof \DateTimeImmutable ? $this->value->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT) : $this->value,
            'i' => $this->customerId,
        ];

        return rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * The name of a cursor is the stored name of a customer in lower case, as the sort compares it: any text but one
     * with a NUL (PostgreSQL has none in a text), also a name stored before the rule of texts (spec
     * api-input-contract, E7), so the list pages over it.
     *
     * @phpstan-assert-if-true string $value
     */
    private static function isName(mixed $value): bool
    {
        return \is_string($value) && '' !== $value && mb_strlen($value) <= self::MAX_NAME_LENGTH && !str_contains($value, "\0");
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isText(mixed $value, int $maxLength): bool
    {
        return \is_string($value) && '' !== $value && mb_strlen($value) <= $maxLength && !ControlCharacters::in($value);
    }

    /**
     * @throws InvalidBackofficeCustomerCursor
     */
    private static function decodeMoment(mixed $value): \DateTimeImmutable
    {
        // Only a moment written in the exact format of encode() is a cursor: "2026-02-31" would be moved to March.
        if (!\is_string($value) || 1 !== preg_match(self::TIME_PATTERN, $value)) {
            throw new InvalidBackofficeCustomerCursor();
        }
        $at = \DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $value);
        if (false === $at || $at->format(self::TIME_FORMAT) !== $value || (int) $at->format('Y') < 1) {
            throw new InvalidBackofficeCustomerCursor();
        }

        return $at;
    }
}
