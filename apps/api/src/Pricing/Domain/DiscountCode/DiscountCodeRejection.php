<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * A code that gives no discount, and why (D6). The one place of the problem types and the Polish texts of the
 * rejections: the preview of the cart and the 409 of the order take them from here, so both say the same.
 */
final readonly class DiscountCodeRejection
{
    public const string TYPE_INVALID = 'tag:piotrp321.github.io,2026:shop/problems/discount-code-invalid';
    public const string TYPE_USED_UP = 'tag:piotrp321.github.io,2026:shop/problems/discount-code-used-up';
    public const string TYPE_BELOW_MINIMUM = 'tag:piotrp321.github.io,2026:shop/problems/discount-code-below-minimum';

    /**
     * @param int|null $minimumOrderGross the effective minimum (Q7), only for below_minimum
     */
    private function __construct(
        public DiscountCodeRejectionReason $reason,
        public ?int $minimumOrderGross,
    ) {
    }

    /**
     * Also an unknown code.
     */
    public static function invalid(): self
    {
        return new self(DiscountCodeRejectionReason::Invalid, null);
    }

    public static function usedUp(): self
    {
        return new self(DiscountCodeRejectionReason::UsedUp, null);
    }

    public static function belowMinimum(int $minimumOrderGross): self
    {
        return new self(DiscountCodeRejectionReason::BelowMinimum, $minimumOrderGross);
    }

    public function problemType(): string
    {
        return match ($this->reason) {
            DiscountCodeRejectionReason::Invalid => self::TYPE_INVALID,
            DiscountCodeRejectionReason::UsedUp => self::TYPE_USED_UP,
            DiscountCodeRejectionReason::BelowMinimum => self::TYPE_BELOW_MINIMUM,
        };
    }

    public function detail(): string
    {
        return match ($this->reason) {
            DiscountCodeRejectionReason::Invalid => 'Ten kod jest nieprawidłowy albo nieaktywny.',
            DiscountCodeRejectionReason::UsedUp => 'Ten kod został już wykorzystany.',
            DiscountCodeRejectionReason::BelowMinimum => 'Ten kod działa przy zamówieniu od '.self::zloty($this->minimumOrderGross ?? 0).'.',
        };
    }

    /**
     * Grosze as Polish złoty: a space between thousands, a comma before the grosze ("1 000,00 zł").
     */
    private static function zloty(int $grosze): string
    {
        return \sprintf('%s,%02d zł', number_format(intdiv($grosze, 100), 0, '', ' '), $grosze % 100);
    }
}
