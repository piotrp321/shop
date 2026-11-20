<?php

declare(strict_types=1);

namespace App\Delivery\Application\Query;

use App\Delivery\Domain\DeliveryMethodRepository;
use App\Delivery\Domain\DeliveryOption;
use App\Delivery\Domain\DeliveryQuote;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads the active methods (at most about 20 rows along the index of the active ones) without a lock, so it is a plain
 * read inside the transaction of a placement too (A7).
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class QuoteDeliveryHandler
{
    public function __construct(private DeliveryMethodRepository $methods)
    {
    }

    /**
     * @throws \InvalidArgumentException for negative goods
     */
    public function __invoke(QuoteDelivery $query): DeliveryQuoteView
    {
        $quote = DeliveryQuote::of($this->methods->active(), $query->goodsGross);

        return new DeliveryQuoteView(
            $quote->fromGross,
            $quote->missingToFreeGross,
            array_map(static fn (DeliveryOption $option): DeliveryOptionView => new DeliveryOptionView(
                $option->method->id()->toString(),
                $option->method->name()->toString(),
                $option->method->description()->toString(),
                $option->method->trackingUrlTemplate()?->toString(),
                $option->costGross,
                $option->method->freeFrom()?->gross(),
                $option->missingToFreeGross,
            ), $quote->options),
        );
    }
}
