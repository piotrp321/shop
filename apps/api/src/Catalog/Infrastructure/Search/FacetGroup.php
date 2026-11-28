<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

/**
 * The facets of a listing that share one set of conditions on a variant (spec search, M2, "Fasety"): one search of
 * size 0 with these conditions in its query. The kind of the conditions decides how the axes and values are counted:
 *
 * - {@see self::TOKENS}: no option and no price, one terms aggregation over the tokens of the products (`facets`,
 *   with availability `buy_facets`) gives the categories, the axes, the values and the availability at once;
 * - {@see self::PAIRS}: one value of one axis without a price, the tokens of `pair_any` (`pair_buy`) that begin with
 *   the option give the other axes and their values on the variants with it;
 * - {@see self::NESTED}: a price, or several values of one axis: the variants that meet the conditions, counted per
 *   product (reverse_nested);
 * - {@see self::PLAIN}: two axes: no other axis can be on the variant, only categories, a count and the price.
 */
final readonly class FacetGroup
{
    public const string TOKENS = 'tokens';
    public const string PAIRS = 'pairs';
    public const string NESTED = 'nested';
    public const string PLAIN = 'plain';

    /**
     * @param self::TOKENS|self::PAIRS|self::NESTED|self::PLAIN $kind
     * @param list<array{string, list<string>}>                 $options      axis and its wanted values, every axis, at most two
     * @param bool                                              $buyable      the variant can be bought
     * @param bool                                              $priced       the variant's price is in the range of the criteria
     * @param bool                                              $categories   the category facet is counted here
     * @param bool                                              $freeAxes     the axes without selection and their values are counted here
     * @param list<string>                                      $excluded     the selected axes, left out of the axes without selection
     * @param list<array{string, list<string>}>                 $selected     the selected axes (with their selected values) whose values are counted here
     * @param bool                                              $count        the products of the group are counted (the availability facet)
     * @param bool                                              $price        the price range of the variants is counted here
     * @param bool                                              $availability the products with a variant that can be bought are counted from the tokens
     */
    public function __construct(
        public string $kind,
        public array $options,
        public bool $buyable,
        public bool $priced,
        public bool $categories = false,
        public bool $freeAxes = false,
        public array $excluded = [],
        public array $selected = [],
        public bool $count = false,
        public bool $price = false,
        public bool $availability = false,
    ) {
    }

    /**
     * The group of the same conditions counting what both count.
     */
    public function with(self $other): self
    {
        return new self(
            $this->kind,
            $this->options,
            $this->buyable,
            $this->priced,
            $this->categories || $other->categories,
            $this->freeAxes || $other->freeAxes,
            [...$this->excluded, ...$other->excluded],
            [...$this->selected, ...$other->selected],
            $this->count || $other->count,
            $this->price || $other->price,
            $this->availability || $other->availability,
        );
    }

    /**
     * Whether axes or values are counted in the group.
     */
    public function countsValues(): bool
    {
        return $this->freeAxes || [] !== $this->selected;
    }
}
