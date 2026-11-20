<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\UnpaidOrderClaims;
use App\Ordering\Application\Port\UnpaidOrderLock;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\TooManyUnpaidOrders;
use App\Ordering\Domain\UnpaidOrderIdentity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The limit of unpaid orders of a placement (spec: docs/features/abuse-limits/spec.md, L6, D6–D8): at most
 * $perAddress orders waiting for their payment from one address of the client, and $perIdentity of one email of
 * a guest or of one account. An order waits until its expiry (its claim), also when the worker cancels it late; a paid
 * or cancelled order has no claim. The counts run under the locks of the address and of the identity, and the clock
 * is read after them, so concurrent placements never pass the limit together. The values are in
 * config/packages/ordering.yaml.
 */
final readonly class UnpaidOrderLimit
{
    public function __construct(
        private UnpaidOrderLock $lock,
        private UnpaidOrderClaims $claims,
        private Clock $clock,
        #[Autowire(param: 'app.ordering.unpaid_orders_per_address')]
        private int $perAddress,
        #[Autowire(param: 'app.ordering.unpaid_orders_per_identity')]
        private int $perIdentity,
    ) {
    }

    /**
     * Locks the address and the identity for the rest of the transaction and refuses the placement when either
     * already has its limit of unpaid orders.
     *
     * @throws TooManyUnpaidOrders
     */
    public function ensureRoomFor(string $address, UnpaidOrderIdentity $identity): void
    {
        $this->lock->lock($address, $identity);
        $now = $this->clock->now();
        if ($this->claims->countByAddress($address, $now) >= $this->perAddress || $this->claims->countByIdentity($identity, $now) >= $this->perIdentity) {
            throw new TooManyUnpaidOrders();
        }
    }

    /**
     * Claims a place for the order placed under the locks of ensureRoomFor(), until its expiry.
     */
    public function claim(OrderId $orderId, string $address, UnpaidOrderIdentity $identity, \DateTimeImmutable $expiresAt): void
    {
        $this->claims->add($orderId, $address, $identity, $expiresAt);
    }
}
