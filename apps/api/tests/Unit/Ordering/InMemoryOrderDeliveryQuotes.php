<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\OrderDeliveryQuotes;
use App\Ordering\Application\Port\QuotedDeliveryMethod;

/**
 * The methods of Delivery as QuoteDelivery prices them: an active method costs nothing from its threshold (inclusive),
 * its price below it; a disabled or unknown method is not quoted.
 */
final class InMemoryOrderDeliveryQuotes implements OrderDeliveryQuotes
{
    /** @var array<string, array{name: string, template: ?string, price: int, freeFrom: ?int, active: bool}> */
    private array $methods = [];
    /** @var list<array{string, int}> the method id and the goods of each quote */
    public array $asked = [];

    public function __construct(private readonly Journal $journal)
    {
    }

    public function method(string $methodId, string $name, int $price, ?int $freeFrom = null, ?string $template = null): self
    {
        $this->methods[$methodId] = ['name' => $name, 'template' => $template, 'price' => $price, 'freeFrom' => $freeFrom, 'active' => true];

        return $this;
    }

    public function disable(string $methodId): void
    {
        $this->methods[$methodId]['active'] = false;
    }

    public function quote(string $methodId, int $goodsGross): ?QuotedDeliveryMethod
    {
        $this->journal->record('delivery');
        $this->asked[] = [$methodId, $goodsGross];
        $method = $this->methods[$methodId] ?? null;
        if (null === $method || !$method['active']) {
            return null;
        }
        $free = null !== $method['freeFrom'] && $goodsGross >= $method['freeFrom'];

        return new QuotedDeliveryMethod($methodId, $method['name'], $method['template'], $free ? 0 : $method['price']);
    }
}
