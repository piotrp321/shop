<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\Text;

/**
 * What the search term of the order list asks for, once trimmed by the rule of texts (Text: white space and invisible
 * characters at the ends; a term without a visible character is empty): an exact order number (`R-` and digits, any
 * letter case), else an exact email when the text is a complete address (a domain with a dot and a last label of
 * 2 characters or more, so „jan@gm” is not one), else a fragment of 3 to 100 characters searched in the number, the
 * email and the name. Anything else, or any text with a character the rule forbids, is refused. A complete address
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
        // The forbidden characters are looked for in the term as it came: the trim drops some of them at the ends.
        if (Text::hasForbiddenCharacters($q)) {
            throw InvalidBackofficeOrderList::searchTerm();
        }
        $term = Text::normalize($q);
        $length = mb_strlen($term);
        if ($length > ListBackofficeOrders::MAX_QUERY_LENGTH) {
            throw InvalidBackofficeOrderList::searchTerm();
        }
        if (1 === preg_match(self::NUMBER, $term)) {
            return new self('R-'.substr($term, 2), null, null);
        }
        if (1 === preg_match(self::EMAIL, $term)) {
            return new self(null, $term, null);
        }
        if ($length >= self::MIN_FRAGMENT_LENGTH && $length <= self::MAX_FRAGMENT_LENGTH) {
            return new self(null, null, $term);
        }

        throw InvalidBackofficeOrderList::searchTerm();
    }
}
