<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\Port\CartReader;
use App\Cart\Application\Port\Clock;
use App\Cart\Application\ProductDataLoader;
use App\Cart\Application\View\CartView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartRepository;
use App\Cart\Domain\MergeOutcome;
use App\Cart\Domain\VariantId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One transaction of command.bus. The guest cart is locked first and its state read under the lock; only a guest
 * cart that can be merged leads to the lock (or creation) of the customer cart, so two locks are always taken in the
 * same order, guest then customer, and a merge never deadlocks with another merge or with a change of either cart.
 * Both carts are stored in the same transaction: any failure rolls both back, so a guest cart is merged only when its
 * lines are in the customer cart.
 *
 * A merge that moves nothing changes nothing and creates no customer cart: its view is the customer cart read without
 * a lock, as GetCart reads it (the empty view without id for a customer without a cart). Such a merge holds only the
 * lock of the row named as the guest cart, which may be the cart of another customer, so it never waits for a second
 * lock while holding one.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class MergeGuestCartHandler
{
    public function __construct(
        private CartRepository $carts,
        private CartReader $reader,
        private ProductDataLoader $products,
        private CartViewBuilder $views,
        private Clock $clock,
    ) {
    }

    public function __invoke(MergeGuestCart $command): MergeResult
    {
        $now = $this->clock->now();
        $guest = $this->lockGuest($command->guestCartId);
        $refused = null === $guest ? MergeOutcome::nothingToMerge() : $guest->outcomeWithoutMerge($now);
        if (null !== $refused) {
            return $this->result($this->viewWithoutChange($command->customerId, $now), $refused);
        }

        $customer = $this->carts->lockOrCreateForCustomer($command->customerId, $now);
        $outcome = $customer->mergeFrom($guest, $now);
        $this->carts->save($guest);
        $this->carts->save($customer);

        return $this->result($this->views->build($customer->id(), $customer->lines(), $this->products->load($customer->variantIds())), $outcome);
    }

    /**
     * An id that is not a UUID names no cart.
     */
    private function lockGuest(string $guestCartId): ?Cart
    {
        try {
            $id = CartId::fromString($guestCartId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->carts->lock($id);
    }

    private function viewWithoutChange(string $customerId, \DateTimeImmutable $now): CartView
    {
        $customer = $this->reader->findForCustomer($customerId);
        if (null === $customer) {
            return $this->views->empty(null);
        }

        $lines = $customer->linesAt($now);

        return $this->views->build($customer->id(), $lines, $this->products->load(array_map(static fn (CartLine $line): VariantId => $line->variantId(), $lines)));
    }

    private function result(CartView $view, MergeOutcome $outcome): MergeResult
    {
        return new MergeResult($view, $outcome->result->value, $outcome->truncated);
    }
}
