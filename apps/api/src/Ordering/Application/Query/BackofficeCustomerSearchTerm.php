<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Domain\ControlCharacters;

/**
 * The search term of the customer list: a fragment of the email or of the name, 3 to 100 characters once trimmed (also
 * of the Unicode spaces), without a control character (looked for in the term as it came: a trim would drop some of
 * them at the ends, and PostgreSQL refuses a NUL).
 */
final class BackofficeCustomerSearchTerm
{
    public const int MIN_LENGTH = 3;
    public const int MAX_LENGTH = 100;

    /**
     * @return string the fragment, trimmed, as typed otherwise (the database folds the letter case)
     *
     * @throws InvalidBackofficeCustomerList
     */
    public static function fragment(string $q): string
    {
        if (ControlCharacters::in($q)) {
            throw InvalidBackofficeCustomerList::searchTerm();
        }
        $term = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $q);
        if (!\is_string($term) || mb_strlen($term) < self::MIN_LENGTH || mb_strlen($term) > self::MAX_LENGTH) {
            throw InvalidBackofficeCustomerList::searchTerm();
        }

        return $term;
    }
}
