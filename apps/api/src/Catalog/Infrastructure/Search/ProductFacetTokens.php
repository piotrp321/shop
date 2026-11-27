<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Domain\Product\VariantAxes;

/**
 * The tokens of a product for the facets of the search index (spec search, M1, "Dokument"): the one place that builds
 * them and knows their syntax, for the document and for the queries.
 *
 * - `#<id>`: a category of the product or one of its ancestors;
 * - `@<axis>`: an axis of a variant;
 * - `<axis>` U+001F `<value>`: an option of a variant (the form of {@see VariantDocument::$options});
 * - `$`: some variant can be bought (`facets` only);
 * - `x` U+001E `y`, `x` U+001E `@<axis of y>`: two options of one variant, for a variant with two options.
 *
 * A token is read as: it contains U+001F, so an option; else `#` a category, `@` an axis, `$` the availability. An
 * axis and a value never contain U+001E or U+001F (the rule of texts), so the separators are unambiguous; `#`, `@`,
 * `$` and the characters of regular expressions are allowed in them and escaped by {@see escapeForRegexp()}.
 *
 * The count of options of a variant and the uniqueness of the tuples of options are rules of the domain
 * ({@see VariantAxes::MAX_AXES}, `ProductVariants`) that the model relies on; a product that does not keep them is
 * refused instead of counted wrongly.
 */
final readonly class ProductFacetTokens
{
    public const string PAIR_SEPARATOR = "\u{1E}";
    public const string PURCHASABLE = '$';
    // The kinds of a token read by read().
    public const string READ_OPTION = 'option';
    public const string READ_CATEGORY = 'category';
    public const string READ_AXIS = 'axis';
    public const string READ_PURCHASABLE = 'purchasable';
    private const string CATEGORY = '#';
    // Any text without U+001F, in a regular expression of Lucene: the rest of an axis token, never an option.
    private const string ANY_BUT_OPTION_SEPARATOR = '[^\\'.VariantDocument::OPTION_SEPARATOR.']*';
    private const string AXIS = '@';

    /**
     * @param list<string>       $facets      every token of the product, `$` when a variant can be bought
     * @param list<string>       $buyFacets   the tokens of the variants that can be bought, none without such a variant
     * @param list<string>       $pairAny     the pairs of options of all variants
     * @param list<string>       $pairBuy     the pairs of options of the variants that can be bought
     * @param list<int>          $buyPrices   the distinct prices of the variants that can be bought
     * @param list<list<string>> $variantAxes the axes of each variant, in the order of the variants
     */
    private function __construct(
        public array $facets,
        public array $buyFacets,
        public array $pairAny,
        public array $pairBuy,
        public bool $purchasable,
        public array $buyPrices,
        public ?int $maxGross,
        public array $variantAxes,
    ) {
    }

    /**
     * @throws SearchIndexError when a variant has more than two options, two variants have the same options, an axis or
     *                          a value contains U+001E or U+001F, or an option has no axis separator
     */
    public static function of(ProductDocument $document): self
    {
        $facets = $buyFacets = [];
        foreach ($document->categoryAncestorIds as $categoryId) {
            $facets[] = $buyFacets[] = self::categoryToken($categoryId);
        }

        $pairAny = $pairBuy = $prices = $variantAxes = [];
        $tuples = [];
        $purchasable = false;
        foreach ($document->variants as $variant) {
            $options = self::options($document, $variant);
            $optionTokens = array_map(static fn (array $option): string => self::optionToken($option['axis'], $option['value']), $options);
            $tuple = implode(self::PAIR_SEPARATOR, $optionTokens);
            if (isset($tuples[$tuple])) {
                throw self::refusal($document, 'two variants have the same options');
            }
            $tuples[$tuple] = true;

            $axes = array_column($options, 'axis');
            $tokens = [...array_map(self::axisToken(...), $axes), ...$optionTokens];
            $variantAxes[] = $axes;
            array_push($facets, ...$tokens);

            $pairs = [];
            if (2 === \count($options)) {
                foreach ([[0, 1], [1, 0]] as [$first, $second]) {
                    $pairs[] = self::pairToken($optionTokens[$first], $optionTokens[$second]);
                    $pairs[] = self::pairAxisToken($optionTokens[$first], $options[$second]['axis']);
                }
            }
            array_push($pairAny, ...$pairs);

            if ($variant->purchasable) {
                $purchasable = true;
                array_push($buyFacets, ...$tokens);
                array_push($pairBuy, ...$pairs);
                if (null !== $variant->gross) {
                    $prices[] = $variant->gross;
                }
            }
        }

        if ($purchasable) {
            $facets[] = self::PURCHASABLE;
        } else {
            $buyFacets = [];
        }
        $prices = array_values(array_unique($prices));

        return new self(
            array_values(array_unique($facets)),
            array_values(array_unique($buyFacets)),
            array_values(array_unique($pairAny)),
            array_values(array_unique($pairBuy)),
            $purchasable,
            $prices,
            [] === $prices ? null : max($prices),
            $variantAxes,
        );
    }

    /**
     * `#<id>`: a category of the product or one of its ancestors.
     */
    public static function categoryToken(string $id): string
    {
        return self::CATEGORY.$id;
    }

    /**
     * `@<axis>`: an axis of a variant.
     */
    public static function axisToken(string $axis): string
    {
        return self::AXIS.$axis;
    }

    /**
     * `<axis>` U+001F `<value>`: an option of a variant.
     */
    public static function optionToken(string $axis, string $value): string
    {
        return $axis.VariantDocument::OPTION_SEPARATOR.$value;
    }

    /**
     * `x` U+001E `y` for two option tokens of one variant, in the order of the arguments.
     */
    public static function pairToken(string $first, string $second): string
    {
        return $first.self::PAIR_SEPARATOR.$second;
    }

    /**
     * `x` U+001E `@<axis>`: an option of a variant with the axis of its other option.
     */
    public static function pairAxisToken(string $option, string $axis): string
    {
        return self::pairToken($option, self::axisToken($axis));
    }

    /**
     * The characters of a text that are no letter and no digit, each escaped with a backslash, so the text is a literal
     * in a regular expression of Lucene (the `include` of a `terms` aggregation) and matches only itself. Every other
     * character is special there or may become so, among them `#` and `@`.
     *
     * @throws \InvalidArgumentException when the text is not valid UTF-8
     */
    public static function escapeForRegexp(string $text): string
    {
        $escaped = preg_replace('/[^\p{L}\p{N}]/u', '\\\\$0', $text);
        if (null === $escaped) {
            throw new \InvalidArgumentException('A text to escape for a regular expression must be valid UTF-8.');
        }

        return $escaped;
    }

    /**
     * The `include` that selects the options of one axis from a field of tokens: not the options of an axis that
     * begins with the same text, not a token of another kind.
     */
    public static function optionsOfAxisPattern(string $axis): string
    {
        return self::escapeForRegexp(self::optionToken($axis, '')).'.*';
    }

    /**
     * The `include` that selects the tokens of `pair_*` that begin with one option: the options and the axes paired
     * with it.
     */
    public static function pairPartnersPattern(string $axis, string $value): string
    {
        return self::escapeForRegexp(self::pairToken(self::optionToken($axis, $value), '')).'.*';
    }

    /**
     * The `include` that selects the axis tokens (`@<axis>`) from `facets` or `buy_facets`: not the options of an axis
     * whose name begins with `@`.
     */
    public static function axesPattern(): string
    {
        return self::escapeForRegexp(self::AXIS).self::ANY_BUT_OPTION_SEPARATOR;
    }

    /**
     * The `include` that selects the tokens of `pair_*` that pair one option with an axis (`x` U+001E `@<axis>`): the
     * axes on the variants with the option, not the options of an axis whose name begins with `@`.
     */
    public static function pairAxesPattern(string $axis, string $value): string
    {
        return self::escapeForRegexp(self::pairAxisToken(self::optionToken($axis, $value), '')).self::ANY_BUT_OPTION_SEPARATOR;
    }

    /**
     * The `include` that selects the tokens of `pair_*` that pair one option with the options of another axis
     * (`x` U+001E `<other axis>` U+001F …): the values of that axis on the variants with the option.
     */
    public static function pairOptionsOfAxisPattern(string $axis, string $value, string $otherAxis): string
    {
        return self::escapeForRegexp(self::pairToken(self::optionToken($axis, $value), self::optionToken($otherAxis, ''))).'.*';
    }

    /**
     * Reads a token of `facets` or `buy_facets`, or the part of a pair token after its first option: it contains
     * U+001F, so an option; else `#` a category, `@` an axis, `$` the availability.
     *
     * @return array{self::READ_OPTION, string, string}|array{self::READ_CATEGORY|self::READ_AXIS|self::READ_PURCHASABLE, string, ''} the kind, then the axis and the value of an option, the id of a category, the name of an axis
     *
     * @throws SearchIndexError when the token is of no kind
     */
    public static function read(string $token): array
    {
        if (str_contains($token, VariantDocument::OPTION_SEPARATOR)) {
            [$axis, $value] = explode(VariantDocument::OPTION_SEPARATOR, $token, 2);

            return [self::READ_OPTION, $axis, $value];
        }

        return match (true) {
            self::PURCHASABLE === $token => [self::READ_PURCHASABLE, '', ''],
            str_starts_with($token, self::CATEGORY) => [self::READ_CATEGORY, substr($token, \strlen(self::CATEGORY)), ''],
            str_starts_with($token, self::AXIS) => [self::READ_AXIS, substr($token, \strlen(self::AXIS)), ''],
            default => throw new SearchIndexError('Elasticsearch returned a facet token of no known kind.'),
        };
    }

    /**
     * Reads a token of `pair_*` that begins with the given option ({@see read()} of the rest).
     *
     * @return array{self::READ_OPTION, string, string}|array{self::READ_CATEGORY|self::READ_AXIS|self::READ_PURCHASABLE, string, ''}
     *
     * @throws SearchIndexError when the token does not begin with the option
     */
    public static function readPairPartner(string $token, string $axis, string $value): array
    {
        $prefix = self::pairToken(self::optionToken($axis, $value), '');
        if (!str_starts_with($token, $prefix)) {
            throw new SearchIndexError('Elasticsearch returned a pair token of another option.');
        }

        return self::read(substr($token, \strlen($prefix)));
    }

    /**
     * @return list<array{axis: string, value: string}>
     *
     * @throws SearchIndexError
     */
    private static function options(ProductDocument $document, VariantDocument $variant): array
    {
        if (\count($variant->options) > VariantAxes::MAX_AXES) {
            throw self::refusal($document, \sprintf('a variant has %d options, at most %d are allowed', \count($variant->options), VariantAxes::MAX_AXES));
        }

        $options = [];
        foreach ($variant->options as $option) {
            $parts = explode(VariantDocument::OPTION_SEPARATOR, $option);
            if (2 !== \count($parts)) {
                throw self::refusal($document, 1 === \count($parts) ? 'an option has no axis separator' : 'an axis or a value contains a separator character');
            }
            if (str_contains($option, self::PAIR_SEPARATOR)) {
                throw self::refusal($document, 'an axis or a value contains a separator character');
            }
            $options[] = ['axis' => $parts[0], 'value' => $parts[1]];
        }

        return $options;
    }

    private static function refusal(ProductDocument $document, string $reason): SearchIndexError
    {
        return new SearchIndexError(\sprintf('Product %s breaks a rule of the search model and is not indexed: %s.', $document->id, $reason));
    }
}
