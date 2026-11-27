<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\BackofficeProductList;

/**
 * Which GIN index gives the candidates of a phrase of the product list.
 *
 * pg_trgm extracts a trigram from a `%phrase%` pattern out of its words (runs of letters and digits): a word of three
 * characters or more has trigrams of its own, so the trigram index returns the names that have them. A phrase without
 * such a word (`qx`, `#!#`, `a.b`, `ab-cd`) gives it no trigram, or only the padded edge of a short word that most names
 * have, and the trigram index returns a large part of the table. Its two-character substrings (bigrams) are in few
 * names, so those come from the index of the bigrams. Both are candidates only: the exact condition is `name ILIKE`.
 */
enum PhraseRoute
{
    case Trigrams;
    case Bigrams;

    public static function of(string $phrase): self
    {
        return 1 === preg_match('/[\p{L}\p{N}]{3}/u', $phrase) ? self::Trigrams : self::Bigrams;
    }

    /**
     * The condition on the candidates, in front of `p.name ILIKE`; empty for the trigram index, which `ILIKE` itself uses.
     * The phrase goes in as the parameter `:term`.
     */
    public function candidateCondition(): string
    {
        return self::Bigrams === $this ? 'catalog_name_bigrams(p.name) @> catalog_name_bigrams(CAST(:term AS text))' : '';
    }
}
