<?php

declare(strict_types=1);

namespace App\Cart\Domain;

/**
 * A cart of a guest or of a customer: lines unique by variant, in the order they were added.
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
     * @param array<string, CartLine> $lines by variant id, in the order of addedAt
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
        $byVariant = [];
        foreach ($lines as $line) {
            $byVariant[$line->variantId()->toString()] = $line;
        }

        return new self($id, $owner, $status, $mergedInto, $mergedAt, $byVariant, $createdAt, $updatedAt, $expiresAt);
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
     * @return list<VariantId> in the order of the lines
     */
    public function variantIds(): array
    {
        return array_map(static fn (CartLine $line): VariantId => $line->variantId(), array_values($this->lines));
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
     * (e.g. to decide whether to ask for the offer of a variant).
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
     * Adds a line, or adds the quantity to the line of the variant.
     *
     * @throws GuestCartMerged
     * @throws CartNotFound
     * @throws CartLineQuantityLimit the line would have more than MAX_QUANTITY units
     * @throws CartLineLimit         a new line in a cart of MAX_LINES lines
     * @throws ProductNotPurchasable
     * @throws InsufficientStock
     */
    public function add(VariantId $variantId, Quantity $quantity, ProductOffer $offer, \DateTimeImmutable $now): void
    {
        $this->openForChange($now);

        $key = $variantId->toString();
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
            ? new CartLine($variantId, $quantity, $now)
            : $existing->withQuantity(Quantity::of($result));
        $this->touch($now);
    }

    /**
     * Whether changeQuantity() to this quantity is an increase, which needs the offer of the variant. Call it after
     * openForChange(), so an expired customer cart has no line to increase.
     */
    public function needsOfferToChange(VariantId $variantId, Quantity $quantity): bool
    {
        $existing = $this->lines[$variantId->toString()] ?? null;

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
    public function changeQuantity(VariantId $variantId, Quantity $quantity, ?ProductOffer $offer, \DateTimeImmutable $now): void
    {
        $this->openForChange($now);

        $key = $variantId->toString();
        $existing = $this->lines[$key] ?? throw new CartLineNotFound();
        if ($quantity->value() > $existing->quantity()->value()) {
            if (null === $offer) {
                throw new \LogicException('An increase of the quantity needs the offer of the variant.');
            }
            self::assertPurchasable($offer, $quantity->value());
        }

        $this->lines[$key] = $existing->withQuantity($quantity);
        $this->touch($now);
    }

    /**
     * Removes the line of the variant; without one, nothing changes.
     *
     * @throws GuestCartMerged
     * @throws CartNotFound
     */
    public function remove(VariantId $variantId, \DateTimeImmutable $now): void
    {
        $this->openForChange($now);

        $key = $variantId->toString();
        if (!isset($this->lines[$key])) {
            return;
        }

        unset($this->lines[$key]);
        $this->touch($now);
    }

    /**
     * What a merge of this cart into a customer cart ends with when it moves nothing: already_merged for a merged
     * guest cart (also an expired one), nothing_to_merge for an expired guest cart and for a customer cart; null when
     * the cart can be merged.
     */
    public function outcomeWithoutMerge(\DateTimeImmutable $now): ?MergeOutcome
    {
        if (!$this->owner->isGuest()) {
            return MergeOutcome::nothingToMerge();
        }
        if (CartStatus::Merged === $this->status) {
            return MergeOutcome::alreadyMerged();
        }

        return $this->isExpired($now) ? MergeOutcome::nothingToMerge() : null;
    }

    /**
     * Merges a guest cart into this customer cart. An expired customer cart is cleared first, so it ends with the
     * guest lines only. The guest lines, in the order of addedAt: the quantity of a variant already in the cart is
     * added, cut to MAX_QUANTITY; a new variant becomes a line with the guest's quantity and addedAt while the cart
     * has fewer than MAX_LINES lines, otherwise it is skipped. A cut or a skip makes the outcome truncated. Neither
     * the price nor the availability is checked. The guest cart is marked merged and this cart is touched.
     *
     * A guest cart that cannot be merged (see outcomeWithoutMerge()) changes nothing, neither cart.
     */
    public function mergeFrom(self $guest, \DateTimeImmutable $now): MergeOutcome
    {
        if ($this->owner->isGuest()) {
            throw new \LogicException('A guest cart is merged only into a customer cart.');
        }
        $refused = $guest->outcomeWithoutMerge($now);
        if (null !== $refused) {
            return $refused;
        }

        $this->clearIfExpired($now);
        $truncated = false;
        foreach ($guest->lines() as $line) {
            $key = $line->variantId()->toString();
            $existing = $this->lines[$key] ?? null;
            if (null !== $existing) {
                $sum = $existing->quantity()->value() + $line->quantity()->value();
                $truncated = $truncated || $sum > self::MAX_QUANTITY;
                $this->lines[$key] = $existing->withQuantity(Quantity::of(min($sum, self::MAX_QUANTITY)));
            } elseif (\count($this->lines) < self::MAX_LINES) {
                $this->lines[$key] = $line;
            } else {
                $truncated = true;
            }
        }
        // A guest line may be older than the lines of this cart; the lines stay in the order of addedAt (a stable
        // sort, so lines of the same moment keep the order they got).
        uasort($this->lines, static fn (CartLine $a, CartLine $b): int => $a->addedAt() <=> $b->addedAt());

        $guest->markMerged($this->id, $now);
        $this->touch($now);

        return MergeOutcome::merged($truncated);
    }

    /**
     * Ends an open guest cart once its lines moved into the customer cart: the lines go and the cart never changes
     * again. The expiry stays: a merged cart only waits for its purge.
     */
    public function markMerged(CartId $customerCartId, \DateTimeImmutable $now): void
    {
        if (!$this->owner->isGuest() || CartStatus::Open !== $this->status) {
            throw new \LogicException('Only an open guest cart can be marked merged.');
        }

        $this->lines = [];
        $this->status = CartStatus::Merged;
        $this->mergedInto = $customerCartId;
        $this->mergedAt = $now;
        $this->updatedAt = $now;
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
        match ($offer->purchasability($quantity)) {
            Purchasability::NoPrice, Purchasability::OutOfStock => throw new ProductNotPurchasable(),
            Purchasability::InsufficientStock => throw new InsufficientStock(),
            Purchasability::Purchasable => null,
        };
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
