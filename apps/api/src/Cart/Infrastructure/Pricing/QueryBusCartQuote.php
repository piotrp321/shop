<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure\Pricing;

use App\Cart\Application\Port\CartQuote;
use App\Cart\Application\Port\CartQuoteLine;
use App\Cart\Application\Port\CartQuoteResult;
use App\Cart\Application\Port\QuotedCartLine;
use App\Cart\Application\View\AppliedCartDiscount;
use App\Cart\Application\View\RejectedCartDiscount;
use App\Cart\Domain\Currency;
use App\Cart\Domain\Money;
use App\Cart\Infrastructure\QueryBusFailure;
use App\Pricing\Application\Query\QuoteBasket;
use App\Pricing\Application\Query\QuoteBasketLine;
use App\Pricing\Application\Query\QuotedBasket;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The quote of the purchasable lines through Pricing's QuoteBasket on the query bus. The cart shows the value of a
 * line before the code (the code is one line of the summary), so the shares of the discount are not passed on.
 */
final class QueryBusCartQuote implements CartQuote
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function quote(array $lines, ?string $discountCode): CartQuoteResult
    {
        try {
            $quote = $this->handle(new QuoteBasket(
                array_map(static fn (CartQuoteLine $line): QuoteBasketLine => new QuoteBasketLine($line->variantId->toString(), $line->quantity), $lines),
                $discountCode,
            ));
        } catch (HandlerFailedException $failure) {
            throw QueryBusFailure::cause($failure);
        }
        \assert($quote instanceof QuotedBasket);
        // Pricing sells in PLN only, like the cart.
        $currency = Currency::from($quote->currency);

        $quoted = [];
        foreach ($quote->lines as $line) {
            $quoted[$line->variantId] = new QuotedCartLine(Money::of($line->unit->gross, $currency), Money::of($line->grossBeforeDiscount, $currency));
        }
        $discount = $quote->discount;
        $rejection = $quote->rejection;

        return new CartQuoteResult(
            $quoted,
            Money::of($quote->subtotalGross, $currency),
            Money::of($quote->discountGross, $currency),
            Money::of($quote->gross, $currency),
            match (true) {
                null !== $discount => new AppliedCartDiscount($discount->code, $discount->amountGross, $discount->kind, $discount->value),
                null !== $rejection => new RejectedCartDiscount($rejection->code, $rejection->reason, $rejection->detail, $rejection->minimumOrderGross),
                default => null,
            },
        );
    }
}
