<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Variants newly added to a promotion that cannot be in it: unknown to Catalog (also a variant of a draft), or without
 * a current catalog price (Q13: no price, or one at an outdated rate). The HTTP layer shows each at its place in the
 * request.
 */
final class InvalidPromotionVariants extends \DomainException implements InvalidInput
{
    public const string UNKNOWN = 'Wariant produktu nie istnieje.';
    public const string WITHOUT_PRICE = 'Wariant bez ceny nie może być w promocji.';

    /**
     * @param list<string> $unknown      the variant ids unknown to Catalog, in the order of the request
     * @param list<string> $withoutPrice the variant ids without a current price, in the order of the request
     */
    public function __construct(public readonly array $unknown, public readonly array $withoutPrice)
    {
        parent::__construct([] !== $unknown ? self::UNKNOWN : self::WITHOUT_PRICE);
    }

    /**
     * The message for one variant, null when it is not refused.
     */
    public function reasonFor(string $variantId): ?string
    {
        return match (true) {
            \in_array($variantId, $this->unknown, true) => self::UNKNOWN,
            \in_array($variantId, $this->withoutPrice, true) => self::WITHOUT_PRICE,
            default => null,
        };
    }
}
