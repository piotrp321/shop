<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Port\CartDelivery;
use App\Cart\Application\Port\CartDeliveryOption;
use App\Cart\Application\Port\CartDeliveryQuote;

/**
 * Delivery as Cart's port sees it: a fixed answer (no options by default) and the goods values it was asked about.
 */
final class FakeCartDelivery implements CartDelivery
{
    /** @var list<int> the goods value of every call */
    public array $calls = [];
    private CartDeliveryQuote $answer;

    public function __construct()
    {
        $this->answer = new CartDeliveryQuote(null, null, []);
    }

    /**
     * @param list<CartDeliveryOption> $options
     */
    public function answers(?int $fromGross, ?int $missingToFreeGross, array $options): self
    {
        $this->answer = new CartDeliveryQuote($fromGross, $missingToFreeGross, $options);

        return $this;
    }

    public function quote(int $goodsGross): CartDeliveryQuote
    {
        $this->calls[] = $goodsGross;

        return $this->answer;
    }
}
