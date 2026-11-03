<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * A cart of a guest or of a customer: lines unique by product, in the order they were added.
 *
 * Every change first checks that the cart may change (openForChange): a merged guest cart never changes again
 * (GuestCartMerged) and an expired guest cart does not exist (CartNotFound); an expired customer cart loses its lines
 * (clearIfExpired) and starts anew. Every successful change ends with touch(), which moves the expiry.
 *
 * The constants are the one place of the limits and lifetimes.
 */
final class Cart
{
    public const int MAX_LINES = 50;
    public const int MAX_QUANTITY = 99;
    public const int GUEST_LIFETIME_DAYS = 30;
    public const int CUSTOMER_LIFETIME_DAYS = 180;

    /**
     * @param array<string, CartLine> $lines by product id, in the order of addedAt
     */
    private function __construct(
        private readonly CartId $id,
        private readonly CartOwner $owner,
        private CartStatus $status,
        private ?CartId $mergedInto,
        private ?\DateTimeImmutable $mergedAt,
        private array $lines,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private \DateTimeImmutable $expiresAt,
    ) {
        if (CartStatus::Open !== $status && !$owner->isGuest()) {
            throw new \InvalidArgumentException('Only a guest cart can be merged.');
        }
    }

    public static function createForGuest(CartId $id, \DateTimeImmutable $now): self
    {
        return self::create($id, CartOwner::guest(), $now);
    }

    public static function createForCustomer(CartId $id, string $customerId, \DateTimeImmutable $now): self
    {
        return self::create($id, CartOwner::customer($customerId), $now);
    }

    /**
     * The cart as stored. The lines come in the order of addedAt.
     *
     * @param list<CartLine> $lines
     */
    public static function reconstitute(
        CartId $id,
        CartOwner $owner,
        CartStatus $status,
        ?CartId $mergedInto,
        ?\DateTimeImmutable $mergedAt,
        array $lines,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $expiresAt,
    ): self {
        $byProduct = [];
        foreach ($lines as $line) {
            $byProduct[$line->productId()->toString()] = $line;
        }

        return new self($id, $owner, $status, $mergedInto, $mergedAt, $byProduct, $createdAt, $updatedAt, $expiresAt);
    }

    private static function create(CartId $id, CartOwner $owner, \DateTimeImmutable $now): self
    {
        return new self($id, $owner, CartStatus::Open, null, null, [], $now, $now, self::expiryOf($owner, $now));
    }

    public function id(): CartId
    {
        return $this->id;
    }

    public function owner(): CartOwner
    {
        return $this->owner;
    }

    public function status(): CartStatus
    {
        return $this->status;
    }

    public function mergedInto(): ?CartId
    {
        return $this->mergedInto;
    }

    public function mergedAt(): ?\DateTimeImmutable
    {
        return $this->mergedAt;
    }

    /**
     * The stored lines, whether or not the cart expired.
     *
     * @return list<CartLine> in the order of addedAt
     */
    public function lines(): array
    {
        return array_values($this->lines);
    }

    /**
     * @return list<ProductId> in the order of the lines
     */
    public function productIds(): array
    {
        return array_map(static fn (CartLine $line): ProductId => $line->productId(), array_values($this->lines));
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt < $now;
    }

    /**
     * The lines as a reader sees them: an expired customer cart is empty.
     *
     * @return list<CartLine>
     *
     * @throws GuestCartMerged
     * @throws CartNotFound    an expired guest cart
     */
    public function linesAt(\DateTimeImmutable $now): array
    {
        if ($this->owner->isGuest()) {
            $this->assertGuestCartAvailable($now);

            return $this->lines();
        }

        return $this->isExpired($now) ? [] : $this->lines();
    }

    /**
     * The units of all lines as a reader sees them (see linesAt()).
     *
     * @throws GuestCartMerged
     * @throws CartNotFound
     */
    public function itemCountAt(\DateTimeImmutable $now): int
    {
        return array_sum(array_map(static fn (CartLine $line): int => $line->quantity()->value(), $this->linesAt($now)));
    }

    /**
     * Where every change starts; idempotent. A handler calls it first when it needs the state the change will see
     * (e.g. to decide whether to ask for the offer of a product).
     *
     * @throws GuestCartMerged
     * @throws CartNotFound    an expired guest cart
     */
    public function openForChange(\DateTimeImmutable $now): void
    {
        if ($this->owner->isGuest()) {
            $this->assertGuestCartAvailable($now);

            return;
        }

        $this->clearIfExpired($now);
    }

    /**
     * Adds a line, or adds the quantity to the line of the product.
     *
     * @throws GuestCartMerged
     * @throws CartNotFound
     * @throws CartLineQuantityLimit the line would have more than MAX_QUANTITY units
     * @throws CartLineLimit         a new line in a cart of MAX_LINES lines
     * @throws ProductNotPurchasable
     * @throws InsufficientStock
     */
    public function add(ProductId $productId, Quantity $quantity, ProductOffer $offer, \DateTimeImmutable $now): void
    {
        $this->openForChange($now);

        $key = $productId->toString();
        $existing = $this->lines[$key] ?? null;
        $result = ($existing?->quantity()->value() ?? 0) + $quantity->value();
        if ($result > self::MAX_QUANTITY) {
            throw new CartLineQuantityLimit();
        }
        if (null === $existing && \count($this->lines) >= self::MAX_LINES) {
            throw new CartLineLimit();
        }
        self::assertPurchasable($offer, $result);

        $this->lines[$key] = null === $existing
            ? new CartLine($productId, $quantity, $now)
            : $existing->withQuantity(Quantity::of($result));
        $this->touch($now);
    }

    /**
     * Whether changeQuantity() to this quantity is an increase, which needs the offer of the product. Call it after
     * openForChange(), so an expired customer cart has no line to increase.
     */
    public function needsOfferToChange(ProductId $productId, Quantity $quantity): bool
    {
        $existing = $this->lines[$productId->toString()] ?? null;

        return null !== $existing && $quantity->value() > $existing->quantity()->value();
    }

    /**
     * Sets the quantity of a line. A decrease and the same quantity are always allowed; an increase is checked
     * against the offer like an addition.
     *
     * @param ProductOffer|null $offer required for an increase (see needsOfferToChange())
     *
     * @throws GuestCartMerged
     * @throws CartNotFound
     * @throws CartLineNotFound
     * @throws ProductNotPurchasable
     * @throws InsufficientStock
     */
    public function changeQuantity(ProductId $productId, Quantity $quantity, ?ProductOffer $offer, \DateTimeImmutable $now): void
    {
        $this->openForChange($now);

        $key = $productId->toString();
        $existing = $this->lines[$key] ?? throw new CartLineNotFound();
        if ($quantity->value() > $existing->quantity()->value()) {
            if (null === $offer) {
                throw new \LogicException('An increase of the quantity needs the offer of the product.');
            }
            self::assertPurchasable($offer, $quantity->value());
        }

        $this->lines[$key] = $existing->withQuantity($quantity);
        $this->touch($now);
    }

    /**
     * Removes the line of the product; without one, nothing changes.
     *
     * @throws GuestCartMerged
     * @throws CartNotFound
     */
    public function remove(ProductId $productId, \DateTimeImmutable $now): void
    {
        $this->openForChange($now);

        $key = $productId->toString();
        if (!isset($this->lines[$key])) {
            return;
        }

        unset($this->lines[$key]);
        $this->touch($now);
    }

    /**
     * An expired customer cart loses all its lines. A guest cart is never cleared: once expired it is gone.
     */
    public function clearIfExpired(\DateTimeImmutable $now): void
    {
        if (!$this->owner->isGuest() && $this->isExpired($now)) {
            $this->lines = [];
        }
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
        $this->expiresAt = self::expiryOf($this->owner, $now);
    }

    private static function expiryOf(CartOwner $owner, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $days = $owner->isGuest() ? self::GUEST_LIFETIME_DAYS : self::CUSTOMER_LIFETIME_DAYS;

        return $now->add(new \DateInterval('P'.$days.'D'));
    }

    /**
     * @throws ProductNotPurchasable
     * @throws InsufficientStock
     */
    private static function assertPurchasable(ProductOffer $offer, int $quantity): void
    {
        if (!$offer->hasCurrentPrice || 0 === $offer->available) {
            throw new ProductNotPurchasable();
        }
        if ($quantity > $offer->available) {
            throw new InsufficientStock();
        }
    }

    /**
     * @throws GuestCartMerged
     * @throws CartNotFound
     */
    private function assertGuestCartAvailable(\DateTimeImmutable $now): void
    {
        if (CartStatus::Merged === $this->status) {
            throw new GuestCartMerged();
        }
        if ($this->isExpired($now)) {
            throw new CartNotFound();
        }
    }
}
