<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageRepository;

final class InMemoryDiscountCodeUsages implements DiscountCodeUsageRepository
{
    /** @var array<string, DiscountCodeUsage> by order */
    private array $usages = [];

    public function __construct(private readonly PricingJournal $journal = new PricingJournal())
    {
    }

    public function add(DiscountCodeUsage $usage): void
    {
        if (isset($this->usages[$usage->orderId()->toString()])) {
            throw new \LogicException('The order has a usage already.');
        }
        $this->usages[$usage->orderId()->toString()] = $usage;
    }

    public function activeUsesOf(DiscountCodeId $codeId): int
    {
        $this->journal->add('active uses');

        return \count(array_filter($this->usages, static fn (DiscountCodeUsage $usage): bool => $usage->isActive() && $usage->codeId()->equals($codeId)));
    }
}
