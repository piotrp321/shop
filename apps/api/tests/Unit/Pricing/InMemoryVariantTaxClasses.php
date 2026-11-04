<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\VariantTaxClasses;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Tax\TaxClass;

final class InMemoryVariantTaxClasses implements VariantTaxClasses
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, string> $classes variant id => tax class code
     */
    public function __construct(private array $classes = [])
    {
    }

    public function taxClassOf(VariantId $variantId): ?TaxClass
    {
        $this->asked[] = $variantId->toString();
        $code = $this->classes[$variantId->toString()] ?? null;

        return null === $code ? null : TaxClass::fromString($code);
    }
}
