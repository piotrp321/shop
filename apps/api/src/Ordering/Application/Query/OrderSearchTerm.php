<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Domain\ControlCharacters;

/**
 * What the search term of the order list asks for, once trimmed (also of the Unicode spaces): an exact order number
 * (`R-` and digits, any letter case), else a fragment of 3 to 100 characters searched in the number, the email and the
 * name (a whole email is one too: it contains itself, so it finds what an exact search finds, and more), else, for an
 * email too long for a fragment, an exact email. Anything else, or any text with a control character, is refused.
 */
final readonly class OrderSearchTerm
{
    public const int MIN_FRAGMENT_LENGTH = 3;
    public const int MAX_FRAGMENT_LENGTH = 100;
    /** An order number as staff type it: R- and digits, in any letter case (P9). */
    private const string NUMBER = '/^[Rr]-[0-9]+$/D';

    private function __construct(
        /** `R-` and digits, as stored. */
        public ?string $number,
        /** A whole email longer than a fragment, as typed. */
        public ?string $email,
        /** As typed; the database folds the letter case. */
        public ?string $fragment,
    ) {
    }

    /**
     * @throws InvalidBackofficeOrderList
     */
    public static function parse(string $q): self
    {
        // The control characters are looked for in the term as it came: a trim would drop some of them at the ends.
        if (ControlCharacters::in($q)) {
            throw InvalidBackofficeOrderList::searchTerm();
        }
        $term = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $q);
        if (!\is_string($term)) {
            throw InvalidBackofficeOrderList::searchTerm();
        }
        if (1 === preg_match(self::NUMBER, $term)) {
            return new self('R-'.substr($term, 2), null, null);
        }
        $length = mb_strlen($term);
        if ($length >= self::MIN_FRAGMENT_LENGTH && $length <= self::MAX_FRAGMENT_LENGTH) {
            return new self(null, null, $term);
        }
        if ($length > self::MAX_FRAGMENT_LENGTH && $length <= ListBackofficeOrders::MAX_QUERY_LENGTH && str_contains($term, '@')) {
            return new self(null, $term, null);
        }

        throw InvalidBackofficeOrderList::searchTerm();
    }
}
