<?php

declare(strict_types=1);

namespace App\DemoData\Verify;

use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Copy\Sql;
use App\DemoData\Profile\RunContext;
use App\Ordering\Domain\OrderNumber;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionalPrice;
use Doctrine\DBAL\Connection;

/**
 * The order and stock checks of demo-data:verify (steps 5 to 7, spec "Spójność"): the number of orders of the profile;
 * every generated variant has a stock record, its reserved stock is the sum of its active reservations and its last
 * movement is its stock; a paid order has an active reservation and a succeeded payment of its total; the lines of an
 * order less its discount are its total, and their sums are its totals; an order with a code has its usage; an order of
 * a customer is of a generated account; the history of an order ends with its status; the unit price of a line is the
 * effective price of its variant at the placement (the last history entry then, lowered by the promotion running then
 * by the rule of Pricing) with the copy of that promotion. The line prices are checked for every order in the small
 * profiles and for one order in `orders / 100000` (by the index of the order) in the larger ones; every other check is
 * complete. Returns the violations; none means the orders and the stock are consistent.
 */
final readonly class OrdersVerifier
{
    private const int ORDERS_PER_PRICED_ORDER = 100_000;

    public function __construct(
        private Connection $connection,
        private PersonDictionaries $people,
        private SeedCatalog $seed,
    ) {
    }

    /**
     * One order in this many has the prices of its lines checked: all of them up to 100000 orders.
     */
    public static function priceSample(int $orders): int
    {
        return max(1, intdiv($orders, self::ORDERS_PER_PRICED_ORDER));
    }

    /**
     * @return list<string>
     */
    public function violations(RunContext $context): array
    {
        $seedSlugs = Sql::textArray(array_keys($this->seed->products()));

        return [
            ...$this->orderCount($context),
            ...$this->variantsWithoutStock($seedSlugs),
            ...$this->listed('The reserved stock of a variant is not the sum of its active reservations (variants)',
                "SELECT s.variant_id FROM inventory_stock_item s WHERE s.reserved <> coalesce((SELECT sum(l.quantity) FROM inventory_reservation_line l JOIN inventory_reservation r ON r.reservation_key = l.reservation_key
                 WHERE r.status = 'active' AND l.variant_id = s.variant_id), 0) ORDER BY s.variant_id"),
            ...$this->listed('The last movement of a variant is not its stock (variants)',
                'SELECT s.variant_id FROM inventory_stock_item s JOIN LATERAL (SELECT m.physical_after, m.reserved_after FROM inventory_stock_movement m WHERE m.variant_id = s.variant_id ORDER BY m.occurred_at DESC, m.id DESC LIMIT 1) l ON true
                 WHERE (l.physical_after, l.reserved_after) IS DISTINCT FROM (s.physical, s.reserved) ORDER BY s.variant_id'),
            ...$this->listed('A paid order has no active reservation',
                "SELECT o.number FROM ordering_order o WHERE o.status = 'paid' AND NOT EXISTS (SELECT 1 FROM inventory_reservation r WHERE r.reservation_key = o.id::text AND r.status = 'active') ORDER BY o.number"),
            ...$this->listed('The lines of an order less its discount are not its total',
                'SELECT o.number FROM ordering_order o JOIN (SELECT order_id, sum(unit_gross * quantity) AS value, sum(discount_gross) AS discount, sum(line_net) AS net, sum(line_vat) AS vat, sum(line_gross) AS gross FROM ordering_order_line GROUP BY order_id) l ON l.order_id = o.id
                 WHERE l.value - coalesce(o.discount_gross, 0) <> o.total_gross OR l.discount <> coalesce(o.discount_gross, 0) OR (l.net, l.vat, l.gross) IS DISTINCT FROM (o.total_net, o.total_vat, o.total_gross) ORDER BY o.number'),
            ...$this->listed('A paid order has no succeeded payment of its total',
                "SELECT o.number FROM ordering_order o WHERE o.paid_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM payment_payment p WHERE p.id = o.payment_id AND p.order_id = o.id AND p.status = 'succeeded' AND p.amount = o.total_gross) ORDER BY o.number"),
            ...$this->listed('An order with a discount code has no usage of the code',
                'SELECT o.number FROM ordering_order o WHERE o.discount_code IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pricing_discount_code_usage u JOIN pricing_discount_code c ON c.id = u.code_id WHERE u.order_id = o.id AND c.code = o.discount_code) ORDER BY o.number'),
            ...$this->listed('The history of an order does not end with its status',
                'SELECT o.number FROM ordering_order o LEFT JOIN LATERAL (SELECT h.to_status FROM ordering_order_status_change h WHERE h.order_id = o.id ORDER BY h.position DESC LIMIT 1) l ON true
                 WHERE l.to_status IS DISTINCT FROM o.status ORDER BY o.number'),
            ...$this->foreignCustomers($context),
            ...$this->linePrices(self::priceSample($context->profile->orders)),
        ];
    }

    /**
     * @return list<string>
     */
    private function orderCount(RunContext $context): array
    {
        $count = Sql::int($this->connection->fetchOne('SELECT count(*) FROM ordering_order'));

        return $count === $context->profile->orders ? [] : [\sprintf('The profile %s has %d orders of the generator, the table has %d.', $context->profile->name, $context->profile->orders, $count)];
    }

    /**
     * @return list<string>
     */
    private function variantsWithoutStock(string $seedSlugs): array
    {
        /** @var list<string> $slugs */
        $slugs = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT p.slug FROM catalog_product_variant v JOIN catalog_product p ON p.id = v.product_id
             WHERE NOT (p.slug = ANY(?::text[])) AND NOT EXISTS (SELECT 1 FROM inventory_stock_item s WHERE s.variant_id = v.id) ORDER BY p.slug LIMIT '.(Listing::SHOWN + 1),
            [$seedSlugs],
        );

        return Listing::of('A variant has no stock record (products)', $slugs);
    }

    /**
     * @return list<string>
     */
    private function listed(string $what, string $query): array
    {
        /** @var list<string> $items */
        $items = $this->connection->fetchFirstColumn($query.' LIMIT '.(Listing::SHOWN + 1));

        return Listing::of($what, $items);
    }

    /**
     * The customers of the orders against the ids of the generated accounts (AccountGenerator, the function of the
     * accounts step), in a temporary table.
     *
     * @return list<string>
     */
    private function foreignCustomers(RunContext $context): array
    {
        $accounts = new AccountGenerator($context->namespace, $this->people);
        $this->connection->executeStatement('DROP TABLE IF EXISTS demo_verify_customer');
        $this->connection->executeStatement('CREATE TEMP TABLE demo_verify_customer (id text PRIMARY KEY)');
        for ($first = 1; $first <= $context->profile->customers; $first += 10_000) {
            $ids = [];
            for ($number = $first; $number < $first + 10_000 && $number <= $context->profile->customers; ++$number) {
                $ids[] = $accounts->customerId($number);
            }
            $this->connection->executeStatement('INSERT INTO demo_verify_customer (id) SELECT unnest(CAST(? AS text[]))', [Sql::textArray($ids)]);
        }
        $violations = $this->listed('An order of a customer is not of a generated account',
            "SELECT o.number FROM ordering_order o WHERE o.owner_type = 'customer' AND NOT EXISTS (SELECT 1 FROM demo_verify_customer c WHERE c.id = o.customer_id) ORDER BY o.number");
        $this->connection->executeStatement('DROP TABLE demo_verify_customer');

        return $violations;
    }

    /**
     * The unit price of every line of the sampled orders against the effective price of its variant at the placement:
     * the last history entry not after the placement and the promotion of the variant running then, lowered by the rule
     * of Pricing (PromotionalPrice), and the copy of that promotion on the line.
     *
     * @param int $every one order in this many by its index (number − the first number), 1: all
     *
     * @return list<string>
     */
    private function linePrices(int $every): array
    {
        // The sample is part of the text of the statement (see PricingVerifier::currentPriceNotLastEntry).
        $sample = $every > 1 ? \sprintf(' WHERE (CAST(substr(o.number, 3) AS bigint) - %d) %% %d = 0', OrderNumber::FIRST, $every) : '';
        $rows = $this->connection->iterateAssociative(
            'SELECT o.number, l.unit_gross, l.promotion_id, l.promotion_percent_off, l.regular_unit_gross,
                    (SELECT h.gross_amount FROM pricing_price_history h WHERE h.variant_id = l.variant_id AND h.changed_at <= o.placed_at ORDER BY h.changed_at DESC, h.id DESC LIMIT 1) AS regular,
                    p.id AS active_id, p.percent_off AS active_percent
             FROM ordering_order o JOIN ordering_order_line l ON l.order_id = o.id
             LEFT JOIN LATERAL (SELECT pr.id, pr.percent_off FROM pricing_promotion_variant pv JOIN pricing_promotion pr ON pr.id = pv.promotion_id
                                WHERE pv.variant_id = l.variant_id AND pr.starts_at <= o.placed_at AND o.placed_at < pr.ends_at LIMIT 1) p ON true'.$sample.'
             ORDER BY o.number, l.position',
        );
        $numbers = [];
        foreach ($rows as $row) {
            $number = Sql::string($row['number']);
            if (isset($numbers[$number])) {
                continue;
            }
            if (null === $row['regular']) {
                $numbers[$number] = true;
                continue;
            }
            $regular = GrossPrice::pln(Sql::int($row['regular']));
            $effective = null === $row['active_id'] ? $regular : PromotionalPrice::apply($regular, PercentOff::of(Sql::int($row['active_percent'])));
            $copy = [
                null === $row['promotion_id'] ? null : Sql::string($row['promotion_id']),
                null === $row['promotion_percent_off'] ? null : Sql::int($row['promotion_percent_off']),
                null === $row['regular_unit_gross'] ? null : Sql::int($row['regular_unit_gross']),
            ];
            $expected = null === $row['active_id'] ? [null, null, null] : [Sql::string($row['active_id']), Sql::int($row['active_percent']), $regular->amount()];
            if (Sql::int($row['unit_gross']) !== $effective->amount() || $copy !== $expected) {
                $numbers[$number] = true;
            }
        }

        return Listing::of('The unit price of a line is not the effective price of its variant at the placement', array_keys($numbers));
    }
}
