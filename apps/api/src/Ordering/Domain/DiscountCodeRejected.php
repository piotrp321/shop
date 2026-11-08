<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\ExtendedProblem;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The discount code of the placement gives no discount (decision D6): unknown or inactive, used up, or the subtotal is
 * below its minimum. The problem type and the Polish detail are Pricing's (its DiscountCodeRejection is the one place
 * of them), handed over by the adapters, so the 409 says what the preview of the cart said. Only below the minimum
 * the problem has `minimumOrderGross`.
 */
final class DiscountCodeRejected extends \DomainException implements Conflict, TypedProblem, ExtendedProblem
{
    private function __construct(
        private readonly string $type,
        string $detail,
        private readonly ?int $minimumOrderGross,
    ) {
        parent::__construct($detail);
    }

    public static function of(string $type, string $detail, ?int $minimumOrderGross): self
    {
        return new self($type, $detail, $minimumOrderGross);
    }

    public function problemType(): string
    {
        return $this->type;
    }

    public function problemExtensions(): array
    {
        return null === $this->minimumOrderGross ? [] : ['minimumOrderGross' => $this->minimumOrderGross];
    }
}
