<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Pricing;

use App\Ordering\Application\Port\CartLineToOrder;
use App\Ordering\Application\Port\OrderPrices;
use App\Ordering\Application\Port\OrderQuote;
use App\Ordering\Application\Port\QuotedCodeRejection;
use App\Ordering\Application\Port\QuotedOrderLine;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\LineAmounts;
use App\Ordering\Domain\Money;
use App\Ordering\Domain\OrderDeliveryShare;
use App\Ordering\Domain\OrderDiscount;
use App\Ordering\Domain\OrderDiscountKind;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\PromotionCopy;
use App\Ordering\Domain\VariantId;
use App\Ordering\Infrastructure\BusFailure;
use App\Pricing\Application\Query\DeliveryCostShare;
use App\Pricing\Application\Query\DeliveryCostShares;
use App\Pricing\Application\Query\QuoteBasket;
use App\Pricing\Application\Query\QuoteBasketLine;
use App\Pricing\Application\Query\QuotedBasket;
use App\Pricing\Application\Query\SplitDeliveryCost;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The quote of an order through Pricing's QuoteBasket on the query bus (read from the Pricing tables, in the
 * transaction of the placement), copied into Ordering's values. A quote in a currency an order is not placed in has no
 * line, so the cart is not orderable. The rejection of a code keeps Pricing's type and detail. The split of the cost of
 * a delivery through Pricing's SplitDeliveryCost, in the currency an order is placed in (PLN, the only one of Pricing).
 */
final class QueryBusOrderPrices implements OrderPrices
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function quote(array $lines, ?string $discountCode): OrderQuote
    {
        try {
            $quoted = $this->handle(new QuoteBasket(
                array_map(static fn (CartLineToOrder $line): QuoteBasketLine => new QuoteBasketLine($line->variantId->toString(), $line->quantity), $lines),
                $discountCode,
            ));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
        \assert($quoted instanceof QuotedBasket);

        $currency = Currency::tryFrom($quoted->currency);
        if (null === $currency) {
            return new OrderQuote([], $quoted->subtotalGross, null, null, null);
        }

        $orderLines = [];
        foreach ($quoted->lines as $line) {
            $unit = $line->unit;
            $promotion = $line->promotion;
            $orderLines[VariantId::fromString($line->variantId)->toString()] = new QuotedOrderLine(
                PriceCopy::of($unit->net, $unit->vat, $unit->gross, $unit->vatRateBp, $unit->taxClass, $currency),
                LineAmounts::of($line->discountGross, $line->net, $line->vat, $line->gross, $currency),
                null === $promotion ? null : PromotionCopy::of($promotion->promotionId, $promotion->name, $promotion->percentOff, $line->regularUnitGross),
            );
        }
        $discount = $quoted->discount;
        $rejection = $quoted->rejection;

        return new OrderQuote(
            $orderLines,
            $quoted->subtotalGross,
            null === $discount ? null : OrderDiscount::of($discount->code, OrderDiscountKind::from($discount->kind), $discount->value, Money::of($discount->amountGross, $currency)),
            $discount?->codeId,
            null === $rejection ? null : new QuotedCodeRejection($rejection->type, $rejection->detail, $rejection->minimumOrderGross),
        );
    }

    public function deliveryShares(int $costGross, array $goodsByRate): array
    {
        try {
            $split = $this->handle(new SplitDeliveryCost($costGross, $goodsByRate));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
        \assert($split instanceof DeliveryCostShares);

        return array_map(
            static fn (DeliveryCostShare $share): OrderDeliveryShare => OrderDeliveryShare::of($share->rateBp, $share->gross, $share->net, $share->vat, Currency::PLN),
            $split->shares,
        );
    }
}
