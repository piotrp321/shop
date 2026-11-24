<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\Text;

/**
 * The search term of the customer list: a fragment of the email or of the name, 3 to 100 characters once trimmed by
 * the rule of texts (Text: white space and invisible characters at the ends), without a character the rule forbids
 * (looked for in the term as it came: the trim drops some of them at the ends, and PostgreSQL refuses a NUL).
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
        if (Text::hasForbiddenCharacters($q)) {
            throw InvalidBackofficeCustomerList::searchTerm();
        }
        $term = Text::normalize($q);
        if (mb_strlen($term) < self::MIN_LENGTH || mb_strlen($term) > self::MAX_LENGTH) {
            throw InvalidBackofficeCustomerList::searchTerm();
        }

        return $term;
    }
}
