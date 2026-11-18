<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\GeneratedAccount;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Identity\StaffRoles;
use App\DemoData\Pricing\DiscountCodePlan;
use App\DemoData\Pricing\DiscountCodeSpec;
use App\DemoData\Profile\RunContext;
use App\DemoData\Random\Prng;
use App\Ordering\Domain\AccessToken;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\LineAmounts;
use App\Ordering\Domain\OrderNumber;
use App\Ordering\Domain\PromotionCopy;
use App\Ordering\Domain\RequestFingerprint;
use App\Ordering\Domain\ShippingAddress;
use App\Pricing\Domain\Basket\BasketQuote;
use App\Pricing\Domain\Money\Currency as PricingCurrency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\PriceBreakdown;
use Symfony\Component\Uid\Uuid;

/**
 * The orders of the three years before the anchor (spec: "Zamówienia", "Historia działań", "Identyfikatory, czas
 * i determinizm"; P7, P8). An order has a global index i; a part is a contiguous range of indexes with a stream of its
 * own (`order`, part), so the orders do not depend on the other parts or on the number of processes. The placement time
 * is OrderTraffic of i and the number R-<first value of the sequence + i> (OrderNumber).
 *
 * An order draws its owner (about 60% customers of the generated accounts with a long tail of orders per customer, the
 * contact and address of the account; 40% guests with an address of their own and the e-mail
 * `<first>.<last>.g<number>@example.*`), 1 to 4 lines of 1 to 3 units of variants of products published before the
 * placement (popular products likelier, by Pareto: a fifth of the products takes about four fifths of the draws; among
 * a few candidates the season of the department decides), and about 10% a historical code valid then. The unit price is
 * the effective price of the variant at the placement (PriceTimeline) with the copy of its promotion; the discount of
 * the code and its split over the lines are the rules of the quote of Pricing (BasketQuote), the amounts of a line its
 * PriceBreakdown, checked by LineAmounts of Ordering.
 *
 * The status follows the age (P8: no order is new): about 4% expire unpaid (cancelled by the system 35 minutes after the
 * placement, the usage of the code released), the others are paid by the system within 25 minutes (a succeeded
 * payment of the total), about 2% are then cancelled by staff before shipping, the rest shipped and delivered by staff
 * and about 4.5% of the delivered returned; a cancellation by staff and a return have a refund of the total due 14 days
 * later and settled after that by staff. A step that would come after the anchor does not happen. The staff members
 * have the roles that may do the step (order-management P6: ship and deliver admin or warehouse, cancel, return and
 * settle a refund admin or support).
 *
 * The guest access token and the idempotency keys of the order and the payment are random (random_bytes), never from
 * the seed, which is public: an order cannot be read or placed again from a value computed from the seed.
 */
final class OrderGenerator
{
    public const string STREAM = 'order';
    public const float GUEST_SHARE = 0.40;
    public const float CODE_SHARE = 0.10;
    private const float TIMEOUT_SHARE = 0.04;
    private const float STAFF_CANCEL_SHARE = 0.02;
    private const float RETURN_SHARE = 0.045;
    /** 1, 2, 3 or 4 lines. */
    private const array LINE_WEIGHTS = [45, 30, 15, 10];
    /** 1, 2 or 3 units. */
    private const array QUANTITY_WEIGHTS = [70, 20, 10];
    private const int CANDIDATES = 4;
    private const float HOT_SHARE = 0.8;
    private const int HOT_EVERY = 5;
    private const int HOT_TRIES = 16;
    /** The first products, published before the first order: the last resort of a line whose candidates are not orderable. */
    private const int FALLBACK_PRODUCTS = 8;
    /** The customers by tier: the share of the customer orders and the share of the customers they go to. */
    private const array CUSTOMER_TIERS = [[0.7, 1.0], [0.2, 0.02], [0.1, 0.002]];
    private const int PERMUTATION_PRIME = 1_000_003;
    private const array PROVIDERS = ['demo-transfer', 'demo-card'];
    /** Order::PAYMENT_TIME, and the expiry Order::DELIVERY_WINDOW after the deadline (a test compares them). */
    private const int PAYMENT_SECONDS = 1_800;
    private const int EXPIRY_SECONDS = 2_100;
    /** Order::REFUND_TIME (a test compares them). */
    private const int REFUND_SECONDS = 14 * 86_400;
    private const array DELAYS = [
        'pay' => [60, 1_500],
        'cancel' => [3_600, 129_600],
        'ship' => [7_200, 259_200],
        'deliver' => [86_400, 345_600],
        'return' => [86_400, 1_209_600],
        'settle' => [0, 172_799],
    ];

    private OrderTraffic $traffic;
    private AccountGenerator $accounts;
    /** @var non-empty-list<int> */
    private array $shippers;
    /** @var non-empty-list<int> */
    private array $service;
    /** @var list<DiscountCodeSpec> */
    private array $codes;
    /** @var array<string, int> the most usages of a code with a limit one part may make, by the id of the code */
    private array $partLimits = [];
    private float $codeChance;
    private int $anchor;

    public function __construct(
        private readonly string $namespace,
        \DateTimeImmutable $anchor,
        private readonly int $orders,
        private readonly int $customers,
        int $staff,
        private readonly int $partSize,
        private readonly ProductGenerator $products,
        PersonDictionaries $people,
        DiscountCodePlan $codes,
    ) {
        if ($orders < 1 || $customers < 1 || $staff < 1) {
            throw new \InvalidArgumentException('The orders need at least one order, customer and staff member.');
        }
        $this->anchor = $anchor->getTimestamp();
        $this->traffic = new OrderTraffic($anchor, $orders);
        $this->accounts = new AccountGenerator($namespace, $people);
        $shippers = $service = [];
        for ($number = 1; $number <= $staff; ++$number) {
            $role = StaffRoles::roleOf($number);
            if (\in_array($role, ['admin', 'warehouse'], true)) {
                $shippers[] = $number;
            }
            if (\in_array($role, ['admin', 'support'], true)) {
                $service[] = $number;
            }
        }
        // The first number of a cycle is an admin, so both lists hold at least it.
        $this->shippers = [] === $shippers ? [1] : $shippers;
        $this->service = [] === $service ? [1] : $service;

        $this->codes = $codes->codes();
        $covered = 0;
        foreach ($this->codes as $code) {
            [$first, $last] = $this->indexesOf($code);
            $covered += $last - $first;
            if (null !== $code->usageLimit) {
                $parts = $last > $first ? intdiv($last - 1, $partSize) - intdiv($first, $partSize) + 1 : 1;
                $this->partLimits[$code->id] = intdiv($code->usageLimit->value(), $parts);
            }
        }
        // The codes cover part of the history: the chance inside a code makes about CODE_SHARE of all orders.
        $this->codeChance = 0 === $covered ? 0.0 : min(1.0, self::CODE_SHARE * $orders / $covered);
    }

    public static function forRun(Dictionaries $dictionaries, PersonDictionaries $people, RunContext $context): self
    {
        return new self(
            $context->namespace,
            $context->anchor,
            $context->profile->orders,
            $context->profile->customers,
            $context->profile->staff,
            $context->partSize,
            new ProductGenerator($dictionaries, $context->namespace, $context->anchor, $context->profile->products, $context->partSize),
            $people,
            new DiscountCodePlan($context->namespace, $context->anchor),
        );
    }

    public function parts(): int
    {
        return intdiv($this->orders + $this->partSize - 1, $this->partSize);
    }

    /**
     * The orders of the part, in the order of their indexes.
     *
     * @return list<OrderSpec>
     *
     * @throws \RuntimeException when the catalog lacks the products or the prices of the orders (the earlier steps did not run)
     */
    public function part(int $part, OrderCatalog $catalog): array
    {
        $first = $part * $this->partSize;
        $last = min($first + $this->partSize, $this->orders);
        if ($first >= $last) {
            return [];
        }
        $prng = Prng::forPart($this->namespace, self::STREAM, $part);
        $drafts = [];
        $indexes = range(0, self::FALLBACK_PRODUCTS - 1);
        for ($index = $first; $index < $last; ++$index) {
            $draft = $this->draft($index, $prng);
            foreach ($draft->lines as $line) {
                array_push($indexes, ...$line->candidates);
            }
            $drafts[] = $draft;
        }

        $products = $catalog->products(array_values(array_unique($indexes)));
        $chosen = [];
        $variantIds = [];
        foreach ($drafts as $draft) {
            $lines = $this->chooseLines($draft, $products);
            if ([] === $lines) {
                throw new \RuntimeException(\sprintf('The order %d has no orderable product: run demo-data:products and demo-data:prices first.', $draft->index));
            }
            foreach ($lines as [, $variant]) {
                $variantIds[$variant->id] = true;
            }
            $chosen[] = $lines;
        }
        $timelines = $catalog->timelines(array_keys($variantIds));

        $uses = [];
        $orders = [];
        foreach ($drafts as $position => $draft) {
            $orders[] = $this->order($draft, $chosen[$position], $timelines, $uses);
        }

        return $orders;
    }

    private function draft(int $index, Prng $prng): OrderDraft
    {
        $placedAt = $this->traffic->placedAt($index);
        $id = DemoUuid::v7($placedAt, $prng);
        $guest = $prng->chance(self::GUEST_SHARE);
        $tier = $prng->weighted(array_column(self::CUSTOMER_TIERS, 0));
        $rank = (int) floor($prng->float() * max(1, (int) ceil($this->customers * self::CUSTOMER_TIERS[$tier][1])));

        $published = max(1, $this->products->publishedBy($placedAt));
        $lines = [];
        $count = 1 + $prng->weighted(self::LINE_WEIGHTS);
        for ($line = 0; $line < $count; ++$line) {
            $candidates = [];
            for ($candidate = 0; $candidate < self::CANDIDATES; ++$candidate) {
                $candidates[] = $this->popularProduct($published, $prng);
            }
            $lines[] = new LineDraft($candidates, $prng->float(), $prng->float(), 1 + $prng->weighted(self::QUANTITY_WEIGHTS), DemoUuid::v7($placedAt, $prng));
        }

        $codeDraw = $prng->float();
        $provider = $prng->pick(self::PROVIDERS);
        $outcome = $prng->float();
        $returnDraw = $prng->float();
        $delays = [
            'pay' => $prng->int(...self::DELAYS['pay']),
            'cancel' => $prng->int(...self::DELAYS['cancel']),
            'ship' => $prng->int(...self::DELAYS['ship']),
            'deliver' => $prng->int(...self::DELAYS['deliver']),
            'return' => $prng->int(...self::DELAYS['return']),
            'settle' => $prng->int(...self::DELAYS['settle']),
        ];
        $staff = [
            'ship' => $prng->pick($this->shippers),
            'deliver' => $prng->pick($this->shippers),
            'cancel' => $prng->pick($this->service),
            'return' => $prng->pick($this->service),
            'settle' => $prng->pick($this->service),
        ];
        $paymentId = DemoUuid::v7($placedAt->modify(\sprintf('+%d seconds', $delays['pay'])), $prng);
        $usageId = DemoUuid::v7($placedAt, $prng);

        return new OrderDraft($index, $placedAt, $id, $guest ? null : $this->customerOf($rank), $lines, $codeDraw, $provider, $outcome, $returnDraw, $delays, $staff, $paymentId, $usageId);
    }

    /**
     * A product of the first `published` (all published by then unless drafts): a hot one (a fifth of the products, by
     * a hash of the index, stable over time) with the chance HOT_SHARE, else a cold one.
     */
    private function popularProduct(int $published, Prng $prng): int
    {
        $hot = $prng->chance(self::HOT_SHARE);
        $index = 0;
        for ($try = 0; $try < self::HOT_TRIES; ++$try) {
            $index = $prng->int(0, $published - 1);
            if (self::isHot($index) === $hot) {
                break;
            }
        }

        return $index;
    }

    private static function isHot(int $index): bool
    {
        return 0 === (($index * 2_654_435_761) & 0xFFFFFFFF) % self::HOT_EVERY;
    }

    /**
     * The customer number of a rank: the ranks of a tier are the first ones of a fixed permutation of the customers,
     * so the few customers of the small tiers get most of their orders.
     */
    private function customerOf(int $rank): int
    {
        $prime = 0 === $this->customers % self::PERMUTATION_PRIME ? 999_983 : self::PERMUTATION_PRIME;

        return 1 + ($rank * $prime) % $this->customers;
    }

    /**
     * @param array<int, CatalogProduct> $products
     *
     * @return list<array{CatalogProduct, CatalogVariant, LineDraft}>
     */
    private function chooseLines(OrderDraft $draft, array $products): array
    {
        $lines = [];
        $taken = [];
        foreach ($draft->lines as $line) {
            $orderable = array_values(array_filter(
                array_map(static fn (int $index): ?CatalogProduct => $products[$index] ?? null, $line->candidates),
                fn (?CatalogProduct $product): bool => null !== $product && $this->isOrderable($product, $draft->placedAt),
            ));
            if ([] === $orderable) {
                $orderable = array_values(array_filter(
                    array_map(static fn (int $index): ?CatalogProduct => $products[$index] ?? null, range(0, self::FALLBACK_PRODUCTS - 1)),
                    fn (?CatalogProduct $product): bool => null !== $product && $this->isOrderable($product, $draft->placedAt),
                ));
                $orderable = \array_slice($orderable, 0, 1);
            }
            if ([] === $orderable) {
                continue;
            }
            $weights = array_map(static fn (CatalogProduct $product): float => OrderTraffic::departmentWeight($product->department, $draft->placedAt), $orderable);
            $product = $orderable[self::choose($weights, $line->seasonDraw)];
            if ([] === $product->variants) {
                continue;
            }
            $variant = $product->variants[min(\count($product->variants) - 1, (int) floor($line->variantDraw * \count($product->variants)))];
            if (isset($taken[$variant->id])) {
                continue;
            }
            $taken[$variant->id] = true;
            $lines[] = [$product, $variant, $line];
        }

        return $lines;
    }

    private function isOrderable(?CatalogProduct $product, \DateTimeImmutable $at): bool
    {
        return null !== $product && $product->published && null !== $product->firstPublishedAt && $product->firstPublishedAt < $at;
    }

    /**
     * @param non-empty-list<float> $weights
     */
    private static function choose(array $weights, float $draw): int
    {
        $point = $draw * array_sum($weights);
        foreach ($weights as $index => $weight) {
            $point -= $weight;
            if ($point < 0) {
                return $index;
            }
        }

        return \count($weights) - 1;
    }

    /**
     * @param list<array{CatalogProduct, CatalogVariant, LineDraft}> $lines
     * @param array<string, \App\DemoData\Pricing\PriceTimeline>     $timelines
     * @param array<string, int>                                     $uses      the usages of the codes with a limit made by the part so far
     */
    private function order(OrderDraft $draft, array $lines, array $timelines, array &$uses): OrderSpec
    {
        $at = $draft->placedAt;
        $prices = [];
        $values = [];
        foreach ($lines as [$product, $variant, $line]) {
            $price = ($timelines[$variant->id] ?? null)?->effectiveAt($at)
                ?? throw new \RuntimeException(\sprintf('The variant %s has no price at %s: run demo-data:prices first.', $variant->id, $at->format(\DATE_ATOM)));
            $prices[] = $price;
            $values[] = $price->effective->amount() * $line->quantity;
        }
        $subtotal = array_sum($values);

        $code = $this->codeFor($draft, $subtotal, $uses);
        $discount = null === $code ? 0 : BasketQuote::discountOf($code->value, $subtotal);
        $shares = BasketQuote::allocate($values, $subtotal, $discount);

        $lineSpecs = [];
        $net = $vat = $gross = 0;
        foreach ($lines as $position => [$product, $variant, $line]) {
            $price = $prices[$position];
            $unit = PriceBreakdown::fromGross($price->effective, $price->rate);
            $breakdown = PriceBreakdown::ofLineGross(Money::of($values[$position] - $shares[$position], PricingCurrency::PLN), $price->rate);
            $amounts = LineAmounts::of($shares[$position], $breakdown->net()->amount(), $breakdown->vat()->amount(), $breakdown->gross()->amount(), Currency::PLN);
            $promotion = null === $price->promotion ? null : PromotionCopy::of($price->promotion->id, $price->promotion->name, $price->promotion->percentOff->value(), $price->regular->amount());
            $lineSpecs[] = new OrderLineSpec(
                $line->id, $position, $variant->id, $product->id, $product->name, $variant->label, $line->quantity,
                $unit->net()->amount(), $unit->vat()->amount(), $unit->gross()->amount(), $price->rate->basisPoints(), $product->taxClass,
                $amounts->net()->amount(), $amounts->vat()->amount(), $amounts->gross()->amount(), $amounts->discount()->amount(),
                $promotion?->promotionId(), $promotion?->name(), $promotion?->percentOff(), $promotion?->regularUnitGross()->amount(),
            );
            $net += $amounts->net()->amount();
            $vat += $amounts->vat()->amount();
            $gross += $amounts->gross()->amount();
        }

        $number = OrderNumber::fromSequence(OrderNumber::FIRST + $draft->index);
        [$owner, $account, $person] = $this->owner($draft);
        $email = null === $account ? $person->email('g'.(OrderNumber::FIRST + $draft->index)) : (string) $account->email;
        $contact = Contact::of(EmailAddress::fromString($email), $person->fullName(), $person->phone);
        $address = ShippingAddress::inPoland($person->street, $person->postalCode, $person->city);
        $cart = null === $account ? 'guest:'.DemoUuid::v5($this->namespace, 'cart:'.$draft->index) : 'customer:'.$account->id;
        $fingerprint = RequestFingerprint::of($cart, $contact, $address, $gross, $code?->code->toString());

        $cycle = $this->cycle($draft, $gross);

        return new OrderSpec(
            $draft->index, $draft->id, $number->toString(), $cycle['status'], $owner, $account?->id,
            $contact->email()->toString(), $contact->fullName(), $contact->phone(), $address->street(), $address->postalCode(), $address->city(),
            $net, $vat, $gross,
            IdempotencyKey::fromString(Uuid::v4()->toRfc4122())->toString(),
            $fingerprint->toString(),
            $at, $at->modify(\sprintf('+%d seconds', self::PAYMENT_SECONDS)),
            null === $account ? AccessToken::fromString(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='))->toString() : null,
            $cycle['paidAt'], null === $cycle['paidAt'] ? null : $draft->paymentId,
            $cycle['cancelledAt'], $cycle['cancellationReason'], $cycle['shippedAt'], $cycle['deliveredAt'], $cycle['returnedAt'],
            $cycle['refundAmount'], $cycle['refundDueAt'], $cycle['refundCompletedAt'], $cycle['refundCompletedBy']?->id, $cycle['refundCompletedBy']?->username,
            $code?->code->toString(), $code?->value->kind()->value, $code?->value->value(), null === $code ? null : $discount,
            \count($cycle['history']) + (null === $cycle['refundCompletedAt'] ? 0 : 1),
            $lineSpecs,
            $cycle['history'],
            null === $cycle['paidAt'] ? null : new PaymentSpec(
                $draft->paymentId,
                $draft->provider,
                $gross,
                Uuid::v4()->toRfc4122(),
                // The fingerprint of Payment (RequestFingerprint::of of Payment, compared by a test): the order and the provider.
                hash('sha256', json_encode([$draft->id, $draft->provider], \JSON_THROW_ON_ERROR)),
                $at->modify(\sprintf('+%d seconds', intdiv($draft->delays['pay'], 2))),
                $cycle['paidAt'],
            ),
            null === $code ? null : new CodeUsageSpec($draft->usageId, $code->id, $at, 'payment_timeout' === $cycle['cancellationReason'] ? $cycle['cancelledAt'] : null),
        );
    }

    /**
     * The historical code of the order, if it draws one and one applies: valid at the placement, its minimum reached
     * (DiscountCode::rejectionFor of Pricing: the explicit minimum and the one of a percent) and, for a code with a
     * limit, the share of the limit of this part not used up.
     *
     * @param array<string, int> $uses
     */
    private function codeFor(OrderDraft $draft, int $subtotal, array &$uses): ?DiscountCodeSpec
    {
        if ($draft->codeDraw >= $this->codeChance) {
            return null;
        }
        foreach ($this->codes as $code) {
            if (!$code->period->isValidAt($draft->placedAt)) {
                continue;
            }
            $minimum = max($code->value->implicitMinimumGross() ?? 0, $code->minimumOrder?->gross() ?? 0);
            $limit = $this->partLimits[$code->id] ?? null;
            if ($subtotal < $minimum || (null !== $limit && ($uses[$code->id] ?? 0) >= $limit)) {
                return null;
            }
            $discount = BasketQuote::discountOf($code->value, $subtotal);
            if ($discount < 1 || $discount >= $subtotal) {
                return null;
            }
            if (null !== $limit) {
                $uses[$code->id] = ($uses[$code->id] ?? 0) + 1;
            }

            return $code;
        }

        return null;
    }

    /**
     * @return array{'customer'|'guest', GeneratedAccount|null, \App\DemoData\Accounts\Person}
     */
    private function owner(OrderDraft $draft): array
    {
        if (null === $draft->customer) {
            return ['guest', null, $this->accounts->person('guest', OrderNumber::FIRST + $draft->index)];
        }
        $account = $this->accounts->customer($draft->customer);

        return ['customer', $account, $account->person];
    }

    /**
     * The cycle of the order up to the anchor: the moments of its steps, the status and the history.
     *
     * @return array{status: string, paidAt: ?\DateTimeImmutable, cancelledAt: ?\DateTimeImmutable, cancellationReason: ?string, shippedAt: ?\DateTimeImmutable, deliveredAt: ?\DateTimeImmutable, returnedAt: ?\DateTimeImmutable, refundAmount: ?int, refundDueAt: ?\DateTimeImmutable, refundCompletedAt: ?\DateTimeImmutable, refundCompletedBy: ?GeneratedAccount, history: list<StatusChangeSpec>}
     */
    private function cycle(OrderDraft $draft, int $gross): array
    {
        $placed = $draft->placedAt->getTimestamp();
        $cycle = ['status' => 'new', 'paidAt' => null, 'cancelledAt' => null, 'cancellationReason' => null, 'shippedAt' => null, 'deliveredAt' => null, 'returnedAt' => null, 'refundAmount' => null, 'refundDueAt' => null, 'refundCompletedAt' => null, 'refundCompletedBy' => null, 'history' => []];
        $history = [new StatusChangeSpec(0, null, 'new', $draft->placedAt, 'customer', null, null)];
        $step = function (string $to, int $at, ?int $staff) use (&$history, &$cycle): \DateTimeImmutable {
            $moment = new \DateTimeImmutable('@'.$at);
            $account = null === $staff ? null : $this->accounts->staff($staff);
            $history[] = new StatusChangeSpec(\count($history), $cycle['status'], $to, $moment, null === $account ? 'system' : 'staff', $account?->id, $account?->username);
            $cycle['status'] = $to;

            return $moment;
        };
        $refund = function (int $from) use (&$cycle, $gross, $draft): void {
            $due = $from + self::REFUND_SECONDS;
            $cycle['refundAmount'] = $gross;
            $cycle['refundDueAt'] = new \DateTimeImmutable('@'.$due);
            if ($due + $draft->delays['settle'] < $this->anchor) {
                $cycle['refundCompletedAt'] = new \DateTimeImmutable('@'.($due + $draft->delays['settle']));
                $cycle['refundCompletedBy'] = $this->accounts->staff($draft->staff['settle']);
            }
        };

        if ($draft->outcome < self::TIMEOUT_SHARE) {
            $cycle['cancelledAt'] = $step('cancelled', $placed + self::EXPIRY_SECONDS, null);
            $cycle['cancellationReason'] = 'payment_timeout';
        } else {
            $paid = $placed + $draft->delays['pay'];
            $cycle['paidAt'] = $step('paid', $paid, null);
            if ($draft->outcome < self::TIMEOUT_SHARE + self::STAFF_CANCEL_SHARE) {
                $cancelled = $paid + $draft->delays['cancel'];
                if ($cancelled < $this->anchor) {
                    $cycle['cancelledAt'] = $step('cancelled', $cancelled, $draft->staff['cancel']);
                    $cycle['cancellationReason'] = 'staff';
                    $refund($cancelled);
                }
            } else {
                $shipped = $paid + $draft->delays['ship'];
                $delivered = $shipped + $draft->delays['deliver'];
                $returned = $delivered + $draft->delays['return'];
                if ($shipped < $this->anchor) {
                    $cycle['shippedAt'] = $step('shipped', $shipped, $draft->staff['ship']);
                }
                if ($delivered < $this->anchor) {
                    $cycle['deliveredAt'] = $step('delivered', $delivered, $draft->staff['deliver']);
                }
                if ($draft->returnDraw < self::RETURN_SHARE && $returned < $this->anchor) {
                    $cycle['returnedAt'] = $step('returned', $returned, $draft->staff['return']);
                    $refund($returned);
                }
            }
        }
        $cycle['history'] = $history;

        return $cycle;
    }

    /**
     * The indexes of the orders placed while the code is valid: [first, last).
     *
     * @return array{int, int}
     */
    private function indexesOf(DiscountCodeSpec $code): array
    {
        $end = $code->period->endsAt();

        return [$this->traffic->indexAt($code->period->startsAt()), null === $end ? $this->orders : $this->traffic->indexAt($end)];
    }
}
