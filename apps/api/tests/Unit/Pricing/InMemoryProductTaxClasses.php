<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\ProductTaxClasses;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Tax\TaxClass;

final class InMemoryProductTaxClasses implements ProductTaxClasses
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, string> $classes product id => tax class code
     */
    public function __construct(private array $classes = [])
    {
    }

    public function taxClassOf(ProductId $productId): ?TaxClass
    {
        $this->asked[] = $productId->toString();
        $code = $this->classes[$productId->toString()] ?? null;

        return null === $code ? null : TaxClass::fromString($code);
    }
}
