<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Domain\ControlCharacters;

/**
 * What the search term of the order list asks for, once trimmed (also of the Unicode spaces): an exact order number
 * (`R-` and digits, any letter case), else an exact email when the text is a complete address (a domain with a dot and
 * a last label of 2 characters or more, so „jan@gm” is not one), else a fragment of 3 to 100 characters searched in the
 * number, the email and the name. Anything else, or any text with a control character, is refused. A complete address
 * stays exact, so the link „Inne zamówienia klienta” never lists the orders of „joanna@x.pl” for „anna@x.pl”.
 */
final readonly class OrderSearchTerm
{
    public const int MIN_FRAGMENT_LENGTH = 3;
    public const int MAX_FRAGMENT_LENGTH = 100;
    /** An order number as staff type it: R- and digits, in any letter case (P9). */
    private const string NUMBER = '/^[Rr]-[0-9]+$/D';
    /** A complete email address as far as the search can tell: a local part, an @, labels of a domain with a dot, the last of 2 characters or more. */
    private const string EMAIL = '/^[^@\s]+@(?:[^@\s.]+\.)+[^@\s.]{2,}$/Du';

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
        $length = mb_strlen($term);
        if ($length > ListBackofficeOrders::MAX_QUERY_LENGTH) {
            throw InvalidBackofficeOrderList::searchTerm();
        }
        if (1 === preg_match(self::NUMBER, $term)) {
            return new self('R-'.substr($term, 2), null, null);
        }
        if ($length <= ListBackofficeOrders::MAX_QUERY_LENGTH && 1 === preg_match(self::EMAIL, $term)) {
            return new self(null, $term, null);
        }
        if ($length >= self::MIN_FRAGMENT_LENGTH && $length <= self::MAX_FRAGMENT_LENGTH) {
            return new self(null, null, $term);
        }

        throw InvalidBackofficeOrderList::searchTerm();
    }
}
