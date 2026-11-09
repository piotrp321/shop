<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\Port\CartLineToOrder;
use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\DiscountCodeRedemptions;
use App\Ordering\Application\Port\OrderCart;
use App\Ordering\Application\Port\OrderCatalog;
use App\Ordering\Application\Port\OrderNumbers;
use App\Ordering\Application\Port\OrderPrices;
use App\Ordering\Application\Port\OrderQuote;
use App\Ordering\Application\Port\StockReservations;
use App\Ordering\Application\Port\UnpaidOrderExpiry;
use App\Ordering\Application\View\OrderView;
use App\Ordering\Domain\AccessToken;
use App\Ordering\Domain\CartEmpty;
use App\Ordering\Domain\CartNotOrderable;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\DiscountCodeRejected;
use App\Ordering\Domain\DiscountCodeText;
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
 * Places an order in the one transaction of command.bus: the cart lock, the copies of the quote and the names, the
 * usage of the discount code, the reservation, the order with its OrderPlaced in the outbox and its delayed
 * ExpireUnpaidOrder (due five minutes after the payment deadline) are written together or not at all. A replay
 * schedules nothing and records no second usage. The commands of Cart, Pricing and Inventory run nested in this
 * transaction (through the ports); their exceptions are never caught to go on writing: Inventory's shortage becomes
 * InsufficientStockForOrder and a rejection of the code DiscountCodeRejected of Ordering, both thrown on, every other
 * one is thrown as is.
 *
 * The steps follow the specification ("Złożenie zamówienia"): the replay check before the cart lock (so a replay
 * works also once the guest cart expired or was merged), the lock, the replay check under the lock (a concurrent
 * request with the same key may have placed the order meanwhile), the copies from one quote of Pricing with the code
 * (a rejected code is refused before the total is compared), the total after the discount, the order id and the usage
 * of the code (Pricing locks a code with a limit: the locks are taken cart, code, stock), the number, the cleared
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
        private DiscountCodeRedemptions $redemptions,
        private OrderCatalog $catalog,
        private StockReservations $reservations,
        private OrderNumbers $numbers,
        private UnpaidOrderExpiry $expiry,
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
     * @throws DiscountCodeRejected      in the quote, or when its usage is recorded
     * @throws OrderTotalChanged
     * @throws InsufficientStockForOrder
     */
    public function __invoke(PlaceOrder $command): PlacedOrder
    {
        $key = IdempotencyKey::fromString($command->idempotencyKey);
        $contact = Contact::of(EmailAddress::fromString($command->email), $command->fullName, $command->phone);
        $address = ShippingAddress::inPoland($command->street, $command->postalCode, $command->city);
        $code = DiscountCodeText::normalised($command->discountCode);
        $owner = $command->cart->owner();
        $fingerprint = RequestFingerprint::of($command->cart->normalised(), $contact, $address, $command->expectedTotalGross, $code);

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

        $quote = $this->prices->quote($cartLines, $code);
        $lines = $this->copies($cartLines, $quote);
        if (null !== $quote->rejection) {
            throw DiscountCodeRejected::of($quote->rejection->type, $quote->rejection->detail, $quote->rejection->minimumOrderGross);
        }
        if (OrderTotals::of($lines)->gross()->amount() !== $command->expectedTotalGross) {
            throw new OrderTotalChanged();
        }

        $id = OrderId::fromString(Uuid::v7()->toRfc4122());
        if (null !== $quote->discountCodeId) {
            $this->redemptions->redeem($quote->discountCodeId, $id, $quote->subtotalGross);
        }
        $number = $this->numbers->next();
        $this->cart->clearAfterOrder($command->cart);
        $this->reservations->reserve($id, $cartLines);

        $order = Order::place($id, $number, $owner, $owner->isGuest() ? self::newAccessToken() : null, $contact, $address, $lines, $quote->discount, $key, $fingerprint, $this->clock->now());
        $this->orders->add($order);
        $this->events->publish(...$order->releaseEvents());
        $this->expiry->schedule($order->id(), $order->expiryDueAt());

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
     * The lines of the order in the order of the cart, with the values of the quote and the current names (Catalog
     * asked once).
     *
     * @param non-empty-list<CartLineToOrder> $cartLines
     *
     * @return list<OrderLine>
     *
     * @throws CartNotOrderable a variant without a current price or unknown to Catalog
     */
    private function copies(array $cartLines, OrderQuote $quote): array
    {
        $variants = $this->catalog->variants(array_map(static fn (CartLineToOrder $line): VariantId => $line->variantId, $cartLines));

        $lines = [];
        foreach ($cartLines as $cartLine) {
            $key = $cartLine->variantId->toString();
            $quoted = $quote->lines[$key] ?? null;
            $variant = $variants[$key] ?? null;
            if (null === $quoted || null === $variant) {
                throw new CartNotOrderable();
            }
            $lines[] = OrderLine::of($cartLine->variantId, $variant->productId, $variant->productName, $variant->variantLabel, $cartLine->quantity, $quoted->unitPrice, $quoted->amounts, $quoted->promotion);
        }

        return $lines;
    }
}
