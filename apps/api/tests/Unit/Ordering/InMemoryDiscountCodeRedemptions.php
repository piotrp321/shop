<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\DiscountCodeRedemptions;
use App\Ordering\Domain\OrderId;

/**
 * The usages of the codes recorded and released, in the order of the calls; a redemption fails as told.
 */
final class InMemoryDiscountCodeRedemptions implements DiscountCodeRedemptions
{
    /** @var list<array{string, string, int}> code id, order id and subtotal of each usage */
    public array $redeemed = [];
    /** @var list<string> the order of each release call */
    public array $released = [];
    /** Thrown by the next redemption instead of recording it (e.g. a code used up meanwhile). */
    public ?\Throwable $redeemFailure = null;

    public function __construct(private readonly Journal $journal)
    {
    }

    public function redeem(string $codeId, OrderId $orderId, int $subtotalGross): void
    {
        $this->journal->record('redeem');
        if (null !== $this->redeemFailure) {
            $failure = $this->redeemFailure;
            $this->redeemFailure = null;

            throw $failure;
        }
        $this->redeemed[] = [$codeId, $orderId->toString(), $subtotalGross];
    }

    public function release(OrderId $orderId): void
    {
        $this->journal->record('release usage');
        $this->released[] = $orderId->toString();
    }
}
