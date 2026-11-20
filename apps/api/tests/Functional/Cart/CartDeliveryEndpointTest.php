<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cart;

use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The delivery of the cart read (`CartView.delivery`, W4): priced by Delivery's QuoteDelivery from the value of the
 * goods after the code, for the guest and the customer cart, with and without a code.
 */
final class CartDeliveryEndpointTest extends WebTestCase
{
    use CartEndpointTestTrait;
    use Factories;

    private const string TEMPLATE = 'https://sledzenie.example.com/{numer}';

    public function testACartWithoutAPurchasableLineHasNoDelivery(): void
    {
        $this->methods([['Kurier', 1_999, null]]);
        $cart = $this->guestCart();

        $empty = $this->request('GET', $cart);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($empty['delivery']);

        $soldOut = $this->variant(10_000, 1);
        $this->request('POST', $cart.'/items', json_encode(['variantId' => $soldOut, 'quantity' => 1], \JSON_THROW_ON_ERROR));
        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 0 WHERE variant_id = ?', [$soldOut]);
        $excluded = $this->request('GET', $cart.'?discountCode=WITAJ10');
        self::assertTrue($excluded['hasExcludedLines']);
        self::assertNull($excluded['delivery']);

        $customer = $this->request('GET', '/api/v1/me/cart', token: TestTokenFactory::customer());
        self::assertNull($customer['id']);
        self::assertNull($customer['delivery'], 'a customer without a cart');
    }

    public function testBelowTheThresholdTheMethodsCostAndTheCartShowsTheMissingAmount(): void
    {
        $this->methods([['Kurier', 1_999, 29_900, 10], ['Odbiór w sklepie', 999, null, 20, 'Wtorek–piątek']]);
        $cart = $this->guestCartWith(10_000);

        $view = $this->request('GET', $cart);

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('CartView', $view);
        $delivery = $this->delivery($view);
        self::assertMatchesSchema('CartDelivery', $delivery);
        self::assertSame([999, 19_900], [$delivery['fromGross'], $delivery['missingToFreeGross']]);
        self::assertCount(2, $delivery['options']);
        foreach ($delivery['options'] as $option) {
            self::assertMatchesSchema('CartDeliveryOption', $option);
        }
        self::assertSame(
            [
                ['name' => 'Kurier', 'description' => '', 'costGross' => 1_999, 'freeFromGross' => 29_900, 'missingToFreeGross' => 19_900, 'totalGross' => 11_999],
                ['name' => 'Odbiór w sklepie', 'description' => 'Wtorek–piątek', 'costGross' => 999, 'freeFromGross' => null, 'missingToFreeGross' => null, 'totalGross' => 10_999],
            ],
            array_map(static fn (array $o): array => array_diff_key($o, ['methodId' => true]), $delivery['options']),
            'by position; the total is the goods of the cart plus the cost',
        );
        self::assertSame($this->gross($view) + 1_999, $delivery['options'][0]['totalGross']);
        self::assertTrue(Uuid::isValid($delivery['options'][0]['methodId']));
    }

    public function testFromTheThresholdTheCostIsZero(): void
    {
        $this->methods([['Kurier', 1_999, 10_000]]);
        $cart = $this->guestCartWith(10_000);

        $view = $this->request('GET', $cart);

        $delivery = $this->delivery($view);
        self::assertSame([0, null], [$delivery['fromGross'], $delivery['missingToFreeGross']]);
        self::assertSame([0, null, 10_000], [$delivery['options'][0]['costGross'], $delivery['options'][0]['missingToFreeGross'], $delivery['options'][0]['totalGross']]);
    }

    public function testACodeThatLowersTheGoodsBelowTheThresholdMakesTheDeliveryPaidAgain(): void
    {
        $this->methods([['Kurier', 1_999, 40_000]]);
        $cart = $this->guestCartWith(44_988);
        $this->code(['code' => 'MINUS50', 'kind' => DiscountKind::Amount, 'value' => 5_000, 'minimumOrderGross' => 10_000]);

        $without = $this->request('GET', $cart);
        $with = $this->request('GET', $cart.'?discountCode=minus50');

        self::assertSame(0, $this->delivery($without)['fromGross'], 'above the threshold without the code');
        $delivery = $this->delivery($with);
        self::assertSame(39_988, $this->gross($with));
        self::assertSame([1_999, 12], [$delivery['fromGross'], $delivery['missingToFreeGross']]);
        self::assertSame([1_999, 12, 41_987], [$delivery['options'][0]['costGross'], $delivery['options'][0]['missingToFreeGross'], $delivery['options'][0]['totalGross']]);
    }

    public function testWithoutActiveMethodsTheOptionsAreEmpty(): void
    {
        $this->methods([['Wyłączona', 1_999, null, 10, '', false]]);
        $cart = $this->guestCartWith(10_000);

        $view = $this->request('GET', $cart);

        self::assertSame(['fromGross' => null, 'missingToFreeGross' => null, 'options' => []], $view['delivery']);
    }

    public function testTheTrackingTemplateIsNeverInTheResponse(): void
    {
        $this->methods([['Kurier', 1_999, null, 10, '', true, self::TEMPLATE]]);
        $cart = $this->guestCartWith(10_000);

        $this->request('GET', $cart);

        $content = (string) $this->response()->getContent();
        self::assertStringNotContainsString('sledzenie.example.com', $content);
        self::assertStringNotContainsStringIgnoringCase('tracking', $content);
    }

    public function testAChangeOfTheCartAnswersTheDelivery(): void
    {
        $this->methods([['Kurier', 1_999, 20_000]]);
        $cart = $this->guestCart();
        $variant = $this->variant(15_000, 10);

        $added = $this->request('POST', $cart.'/items', json_encode(['variantId' => $variant, 'quantity' => 1], \JSON_THROW_ON_ERROR));
        $changed = $this->request('PUT', $cart.'/items/'.$variant, json_encode(['quantity' => 2], \JSON_THROW_ON_ERROR));

        self::assertSame([1_999, 5_000], [$this->delivery($added)['fromGross'], $this->delivery($added)['missingToFreeGross']]);
        self::assertSame([0, null], [$this->delivery($changed)['fromGross'], $this->delivery($changed)['missingToFreeGross']]);
    }

    public function testTheCustomerCartHasTheDeliveryWithAndWithoutACode(): void
    {
        $this->methods([['Kurier', 1_999, 20_000]]);
        $token = TestTokenFactory::customer();
        $variant = $this->variant(19_999, 10);
        $this->request('POST', '/api/v1/me/cart/items', json_encode(['variantId' => $variant, 'quantity' => 1], \JSON_THROW_ON_ERROR), $token);
        $this->code(['code' => 'WITAJ10']);

        $without = $this->request('GET', '/api/v1/me/cart', token: $token);
        $with = $this->request('GET', '/api/v1/me/cart?discountCode=WITAJ10', token: $token);

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('CartView', $with);
        self::assertSame([1_999, 1], [$this->delivery($without)['fromGross'], $this->delivery($without)['missingToFreeGross']]);
        $delivery = $this->delivery($with);
        self::assertSame(18_000, $this->gross($with));
        self::assertSame([1_999, 2_000], [$delivery['fromGross'], $delivery['missingToFreeGross']]);
        self::assertSame(19_999, $delivery['options'][0]['totalGross']);
    }

    /**
     * @param array<mixed> $view
     *
     * @return array{fromGross: ?int, missingToFreeGross: ?int, options: list<array{methodId: string, name: string, description: string, costGross: int, freeFromGross: ?int, missingToFreeGross: ?int, totalGross: int}>}
     */
    private function delivery(array $view): array
    {
        self::assertIsArray($view['delivery']);
        /** @var array{fromGross: ?int, missingToFreeGross: ?int, options: list<array{methodId: string, name: string, description: string, costGross: int, freeFromGross: ?int, missingToFreeGross: ?int, totalGross: int}>} $delivery */
        $delivery = $view['delivery'];

        return $delivery;
    }

    /**
     * @param array<mixed> $view
     */
    private function gross(array $view): int
    {
        self::assertIsArray($view['totals']);
        self::assertIsInt($view['totals']['gross']);

        return $view['totals']['gross'];
    }

    /**
     * Replaces the delivery methods of the test database (rolled back after the test).
     *
     * @param list<array{0: string, 1: int, 2: ?int, 3?: int, 4?: string, 5?: bool, 6?: ?string}> $methods name, price, threshold, position, description, active, tracking template
     */
    private function methods(array $methods): void
    {
        $this->connection->executeStatement('DELETE FROM delivery_method');
        foreach ($methods as $method) {
            [$name, $price, $freeFrom, $position, $description, $active, $template] = $method + [3 => 10, 4 => '', 5 => true, 6 => null];
            $this->connection->executeStatement(
                'INSERT INTO delivery_method (id, name, description, price_gross, free_from_gross, active, position, tracking_url_template, created_at, updated_at, version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)',
                [Uuid::v7()->toRfc4122(), $name, $description, $price, $freeFrom, $active, $position, $template],
                [5 => ParameterType::BOOLEAN],
            );
        }
    }

    /**
     * A guest cart with one variant of the given gross price.
     */
    private function guestCartWith(int $gross): string
    {
        $cart = $this->guestCart();
        $this->request('POST', $cart.'/items', json_encode(['variantId' => $this->variant($gross, 10), 'quantity' => 1], \JSON_THROW_ON_ERROR));

        return $cart;
    }

    private function guestCart(): string
    {
        $cart = $this->request('POST', '/api/v1/guest-carts');
        self::assertIsString($cart['id']);

        return '/api/v1/guest-carts/'.$cart['id'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function code(array $attributes): void
    {
        new DoctrineDiscountCodeRepository($this->entityManager)->save(DiscountCodeFactory::createOne($attributes));
        $this->entityManager->clear();
    }
}
