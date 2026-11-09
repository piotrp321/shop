<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cart;

use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The preview of a discount code on the cart: GET /api/v1/guest-carts/{cartId}?discountCode= and
 * GET /api/v1/me/cart?discountCode=, priced by Pricing's QuoteBasket.
 */
final class CartDiscountCodeEndpointTest extends WebTestCase
{
    use CartEndpointTestTrait;
    use Factories;

    private const string INVALID = 'Ten kod jest nieprawidłowy albo nieaktywny.';

    public function testAGuestCartWithAnAppliedPercentCodeHasTheDiscountAndTheTotalAfterIt(): void
    {
        $cart = $this->cartOfShoesAndACap();
        $this->code(['code' => 'WITAJ10']);

        $view = $this->request('GET', $cart.'?discountCode=witaj10');

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('CartView', $view);
        self::assertIsArray($view['lines']);
        self::assertSame([['gross' => 19_999, 'currency' => 'PLN'], ['gross' => 4_990, 'currency' => 'PLN']], array_column($view['lines'], 'unitPrice'));
        self::assertSame([['gross' => 39_998, 'currency' => 'PLN'], ['gross' => 4_990, 'currency' => 'PLN']], array_column($view['lines'], 'lineTotal'), 'the values before the discount');
        // S = 44 988, D = floor(4498,8) = 4498.
        self::assertSame(['subtotalGross' => 44_988, 'discountGross' => 4_498, 'gross' => 40_490, 'currency' => 'PLN', 'itemCount' => 3], $view['totals']);
        self::assertIsArray($view['discount']);
        self::assertMatchesSchema('AppliedCartDiscount', $view['discount']);
        self::assertEquals(['status' => 'applied', 'code' => 'WITAJ10', 'amountGross' => 4_498, 'kind' => 'percent', 'value' => 10], $view['discount']);
    }

    public function testAnAmountCodeAtItsMinimumIsApplied(): void
    {
        $cart = $this->cartOfShoesAndACap();
        $this->code(['code' => 'MINUS50', 'kind' => DiscountKind::Amount, 'value' => 5_000, 'minimumOrderGross' => 44_988]);

        $view = $this->request('GET', $cart.'?discountCode=MINUS50');

        self::assertSame(['subtotalGross' => 44_988, 'discountGross' => 5_000, 'gross' => 39_988, 'currency' => 'PLN', 'itemCount' => 3], $view['totals']);
        self::assertEquals(['status' => 'applied', 'code' => 'MINUS50', 'amountGross' => 5_000, 'kind' => 'amount', 'value' => 5_000], $view['discount']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|null, int, array<string, mixed>}> the code typed, the
     *                                                                                               code stored, its active usages, the discount
     */
    public static function rejections(): iterable
    {
        $invalid = ['status' => 'rejected', 'code' => 'KOD-X', 'reason' => 'invalid', 'detail' => self::INVALID, 'minimumOrderGross' => null];
        yield 'unknown' => ['kod-x', null, 0, $invalid];
        yield 'disabled' => ['kod-x', ['enabled' => false], 0, $invalid];
        yield 'not started' => ['kod-x', ['startsAt' => '+1 day'], 0, $invalid];
        yield 'expired' => ['kod-x', ['startsAt' => '-2 days', 'endsAt' => '-1 day'], 0, $invalid];
        yield 'not a code' => ['kod x', null, 0, ['code' => 'KOD X'] + $invalid];
        yield 'a control character' => ["kod\u{0000}x", null, 0, ['code' => "KOD\u{0000}X"] + $invalid];
        yield 'the longest text' => [str_repeat('x', 64), null, 0, ['code' => str_repeat('X', 64)] + $invalid];
        yield 'used up' => ['KOD-X', ['usageLimit' => 1], 1, ['status' => 'rejected', 'code' => 'KOD-X', 'reason' => 'used_up', 'detail' => 'Ten kod został już wykorzystany.', 'minimumOrderGross' => null]];
        yield 'below the minimum' => ['KOD-X', ['kind' => DiscountKind::Amount, 'value' => 5_000, 'minimumOrderGross' => 100_000], 0, ['status' => 'rejected', 'code' => 'KOD-X', 'reason' => 'below_minimum', 'detail' => 'Ten kod działa przy zamówieniu od 1 000,00 zł.', 'minimumOrderGross' => 100_000]];
    }

    /**
     * @param array<string, mixed>|null $stored
     * @param array<string, mixed>      $expected
     */
    #[DataProvider('rejections')]
    public function testEachRejectionHasItsReasonPolishDetailAndTheTotalsWithoutDiscount(string $typed, ?array $stored, int $activeUses, array $expected): void
    {
        $cart = $this->cartOfShoesAndACap();
        if (null !== $stored) {
            $codeId = $this->code(['code' => 'KOD-X'] + $stored);
            for ($i = 0; $i < $activeUses; ++$i) {
                $this->connection->executeStatement('INSERT INTO pricing_discount_code_usage (id, code_id, order_id, used_at) VALUES (?, ?, ?, NOW())', [Uuid::v7()->toRfc4122(), $codeId, Uuid::v7()->toRfc4122()]);
            }
        }

        $view = $this->request('GET', $cart.'?'.http_build_query(['discountCode' => $typed]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['subtotalGross' => 44_988, 'discountGross' => 0, 'gross' => 44_988, 'currency' => 'PLN', 'itemCount' => 3], $view['totals']);
        self::assertIsArray($view['discount']);
        self::assertMatchesSchema('RejectedCartDiscount', $view['discount']);
        self::assertEquals($expected, $view['discount']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankCodes(): iterable
    {
        yield 'empty' => ['discountCode='];
        yield 'spaces' => ['discountCode=%20%20'];
        yield 'a no-break space' => ['discountCode=%C2%A0'];
    }

    #[DataProvider('blankCodes')]
    public function testABlankCodeIsNoCode(string $query): void
    {
        $cart = $this->cartOfShoesAndACap();
        $without = $this->request('GET', $cart);

        $view = $this->request('GET', $cart.'?'.$query);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($without, $view);
        self::assertNull($view['discount']);
        self::assertSame(['subtotalGross' => 44_988, 'discountGross' => 0, 'gross' => 44_988, 'currency' => 'PLN', 'itemCount' => 3], $view['totals']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidParameters(): iterable
    {
        yield '65 characters' => ['discountCode='.str_repeat('a', 65), 'Kod rabatowy może mieć najwyżej 64 znaki.'];
        yield '65 Polish letters' => ['discountCode='.str_repeat('%C4%85', 65), 'Kod rabatowy może mieć najwyżej 64 znaki.'];
        yield 'an array' => ['discountCode[]=WITAJ10', 'Kod rabatowy musi być tekstem.'];
        yield 'not UTF-8' => ['discountCode=WITAJ%FF', 'Kod rabatowy zawiera niedozwolone znaki.'];
    }

    #[DataProvider('invalidParameters')]
    public function testAnInvalidParameterIs400WithAPolishViolation(string $query, string $title): void
    {
        $cart = $this->cartOfShoesAndACap();

        $problem = $this->request('GET', $cart.'?'.$query);

        $this->assertProblem(400, null, $problem);
        self::assertSame([['propertyPath' => 'discountCode', 'title' => $title]], array_map(
            static fn (mixed $violation): array => \is_array($violation) ? array_intersect_key($violation, ['propertyPath' => true, 'title' => true]) : [],
            \is_array($problem['violations'] ?? null) ? $problem['violations'] : [],
        ));
    }

    public function testACartWithoutPurchasableLinesIsNotQuotedEvenWithACode(): void
    {
        $cart = $this->guestCart();
        $soldOut = $this->variant(10_000, 1);
        $this->request('POST', $cart.'/items', json_encode(['variantId' => $soldOut, 'quantity' => 1], \JSON_THROW_ON_ERROR));
        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 0 WHERE variant_id = ?', [$soldOut]);
        $this->code(['code' => 'WITAJ10']);

        $view = $this->request('GET', $cart.'?discountCode=WITAJ10');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['subtotalGross' => 0, 'discountGross' => 0, 'gross' => 0, 'currency' => 'PLN', 'itemCount' => 1], $view['totals']);
        self::assertNull($view['discount']);
        self::assertTrue($view['hasExcludedLines']);
    }

    public function testACustomerCartWithAnAppliedCode(): void
    {
        $token = TestTokenFactory::customer();
        $shoes = $this->variant(19_999, 10);
        $this->request('POST', '/api/v1/me/cart/items', json_encode(['variantId' => $shoes, 'quantity' => 1], \JSON_THROW_ON_ERROR), $token);
        $this->code(['code' => 'WITAJ10']);

        $view = $this->request('GET', '/api/v1/me/cart?discountCode=%20Witaj10%20', token: $token);

        self::assertResponseStatusCodeSame(200);
        $this->assertNoStore();
        self::assertMatchesSchema('CartView', $view);
        self::assertSame(['subtotalGross' => 19_999, 'discountGross' => 1_999, 'gross' => 18_000, 'currency' => 'PLN', 'itemCount' => 1], $view['totals']);
        self::assertEquals(['status' => 'applied', 'code' => 'WITAJ10', 'amountGross' => 1_999, 'kind' => 'percent', 'value' => 10], $view['discount']);
    }

    public function testACustomerCartRefusesALongCodeAndAMissingToken(): void
    {
        $problem = $this->request('GET', '/api/v1/me/cart?discountCode='.str_repeat('a', 65), token: TestTokenFactory::customer());
        $this->assertProblem(400, null, $problem);

        $this->request('GET', '/api/v1/me/cart?discountCode=WITAJ10');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAChangeOfTheCartAnswersTheViewWithoutACode(): void
    {
        $cart = $this->guestCart();
        $shoes = $this->variant(19_999, 10);

        $added = $this->request('POST', $cart.'/items?discountCode=WITAJ10', json_encode(['variantId' => $shoes, 'quantity' => 1], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        self::assertNull($added['discount']);
        self::assertSame(['subtotalGross' => 19_999, 'discountGross' => 0, 'gross' => 19_999, 'currency' => 'PLN', 'itemCount' => 1], $added['totals']);
    }

    /**
     * A guest cart with 2 shoes at 199,99 zł and a cap at 49,90 zł: 449,88 zł.
     */
    private function cartOfShoesAndACap(): string
    {
        $shoes = $this->variant(19_999, 10);
        $cap = $this->variant(4_990, 10, 'Czapka');
        $cart = $this->guestCart();
        $this->request('POST', $cart.'/items', json_encode(['variantId' => $shoes, 'quantity' => 2], \JSON_THROW_ON_ERROR));
        $this->request('POST', $cart.'/items', json_encode(['variantId' => $cap, 'quantity' => 1], \JSON_THROW_ON_ERROR));

        return $cart;
    }

    private function guestCart(): string
    {
        $cart = $this->request('POST', '/api/v1/guest-carts');
        self::assertIsString($cart['id']);

        return '/api/v1/guest-carts/'.$cart['id'];
    }

    /**
     * Stores a code of DiscountCodeFactory and returns its id.
     *
     * @param array<string, mixed> $attributes
     */
    private function code(array $attributes): string
    {
        $code = DiscountCodeFactory::createOne($attributes);
        new DoctrineDiscountCodeRepository($this->entityManager)->save($code);
        $this->entityManager->clear();

        return $code->id()->toString();
    }
}
