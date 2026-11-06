<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\Port\CartLineToOrder;
use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\OrderCart;
use App\Ordering\Application\Port\OrderCatalog;
use App\Ordering\Application\Port\OrderNumbers;
use App\Ordering\Application\Port\OrderPrices;
use App\Ordering\Application\Port\StockReservations;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\AccessToken;
use App\Ordering\Domain\CartEmpty;
use App\Ordering\Domain\CartNotOrderable;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\IdempotencyKeyReused;
use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\OrderTotalChanged;
use App\Ordering\Domain\OrderTotals;
use App\Ordering\Domain\RequestFingerprint;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\Domain\VariantId;
use App\Shared\Domain\Event\DomainEventPublisher;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Places an order in the one transaction of command.bus: the cart lock, the copies of prices and names, the
 * reservation and the order with its OrderPlaced in the outbox are written together or not at all. The commands of
 * Cart and Inventory run nested in this transaction (through the ports); their exceptions are never caught to go on
 * writing: Inventory's shortage becomes InsufficientStockForOrder and is thrown on, every other one is thrown as is.
 *
 * The steps follow the specification ("Złożenie zamówienia"): the replay check before the cart lock (so a replay
 * works also once the guest cart expired or was merged), the lock, the replay check under the lock (a concurrent
 * request with the same key may have placed the order meanwhile), the copies, the totals, the number, the cleared
 * cart, the reservation (the stock rows stay locked as briefly as possible) and the order last (so the flush of the
 * nested commands writes nothing of Ordering).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class PlaceOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private OrderCart $cart,
        private OrderPrices $prices,
        private OrderCatalog $catalog,
        private StockReservations $reservations,
        private OrderNumbers $numbers,
        private DomainEventPublisher $events,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidOrderInput
     * @throws NotFound                  the guest cart is not available (from Cart)
     * @throws IdempotencyKeyReused
     * @throws CartEmpty
     * @throws CartNotOrderable
     * @throws OrderTotalChanged
     * @throws InsufficientStockForOrder
     */
    public function __invoke(PlaceOrder $command): PlacedOrder
    {
        $key = IdempotencyKey::fromString($command->idempotencyKey);
        $contact = Contact::of(EmailAddress::fromString($command->email), $command->fullName, $command->phone);
        $address = ShippingAddress::inPoland($command->street, $command->postalCode, $command->city);
        $owner = $command->cart->owner();
        $fingerprint = RequestFingerprint::of($command->cart->normalised(), $contact, $address, $command->expectedTotalGross);

        $earlier = $this->orders->findByIdempotencyKey($key);
        if (null !== $earlier && $earlier->isReplayOf($fingerprint)) {
            return new PlacedOrder(OrderView::of($earlier), true);
        }

        $cartLines = $this->cart->lockForOrder($command->cart);

        $earlier = $this->orders->findByIdempotencyKey($key);
        if (null !== $earlier) {
            if (!$earlier->isReplayOf($fingerprint)) {
                throw new IdempotencyKeyReused();
            }

            return new PlacedOrder(OrderView::of($earlier), true);
        }

        if ([] === $cartLines) {
            throw new CartEmpty();
        }

        $lines = $this->copies($cartLines);
        if (OrderTotals::of($lines)->gross()->amount() !== $command->expectedTotalGross) {
            throw new OrderTotalChanged();
        }

        $number = $this->numbers->next();
        $this->cart->clearAfterOrder($command->cart);
        $id = OrderId::fromString(Uuid::v7()->toRfc4122());
        $this->reservations->reserve($id, $cartLines);

        $order = Order::place($id, $number, $owner, $owner->isGuest() ? self::newAccessToken() : null, $contact, $address, $lines, $key, $fingerprint, $this->clock->now());
        $this->orders->add($order);
        $this->events->publish(...$order->releaseEvents());

        return new PlacedOrder(OrderView::of($order), false);
    }

    /**
     * 32 random bytes in base64url without padding: the guest's key to the order, like the id of a guest cart.
     */
    private static function newAccessToken(): AccessToken
    {
        return AccessToken::fromString(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    /**
     * The lines of the order in the order of the cart, with the current prices and names, each port asked once.
     *
     * @param non-empty-list<CartLineToOrder> $cartLines
     *
     * @return list<OrderLine>
     *
     * @throws CartNotOrderable a variant without a current price or unknown to Catalog
     */
    private function copies(array $cartLines): array
    {
        $variantIds = array_map(static fn (CartLineToOrder $line): VariantId => $line->variantId, $cartLines);
        $prices = $this->prices->current($variantIds);
        $variants = $this->catalog->variants($variantIds);

        $lines = [];
        foreach ($cartLines as $cartLine) {
            $key = $cartLine->variantId->toString();
            $price = $prices[$key] ?? null;
            $variant = $variants[$key] ?? null;
            if (null === $price || null === $variant) {
                throw new CartNotOrderable();
            }
            $lines[] = OrderLine::of($cartLine->variantId, $variant->productId, $variant->productName, $variant->variantLabel, $cartLine->quantity, $price);
        }

        return $lines;
    }
}
