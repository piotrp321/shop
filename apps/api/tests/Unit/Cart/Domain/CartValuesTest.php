<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Domain;

use App\Cart\Domain\CartId;
use App\Cart\Domain\CartOwner;
use App\Cart\Domain\Currency;
use App\Cart\Domain\InvalidQuantity;
use App\Cart\Domain\Money;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\ProductOffer;
use App\Cart\Domain\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartValuesTest extends TestCase
{
    public function testCartIdIsAnyUuidLowerCased(): void
    {
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', CartId::fromString('0192F0C4-7C1A-7C4E-9A6E-3F1D2B4C5A60')->toString());
        self::assertTrue(CartId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')->equals(CartId::fromString('0192F0C4-7C1A-7C4E-9A6E-3F1D2B4C5A60')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notUuids(): iterable
    {
        yield 'empty' => [''];
        yield 'no dashes' => ['0192f0c47c1a7c4e9a6e3f1d2b4c5a60'];
        yield 'braces' => ['{0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60}'];
        yield 'not hex' => ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a6g'];
        yield 'trailing new line' => ["0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60\n"];
    }

    #[DataProvider('notUuids')]
    public function testCartIdRefusesWhatIsNotAUuid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CartId::fromString($value);
    }

    #[DataProvider('notUuids')]
    public function testProductIdRefusesWhatIsNotAUuid(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProductId::fromString($value);
    }

    public function testGeneratedCartIdsAreRandomVersion4Uuids(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; ++$i) {
            $id = CartId::generate()->toString();
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id);
            $ids[$id] = true;
        }

        self::assertCount(50, $ids);
    }

    public function testProductIdIsLowerCased(): void
    {
        self::assertSame('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', ProductId::fromString('0192F0C4-7C1A-7C4E-9A6E-3F1D2B4C5A60')->toString());
    }

    public function testOwnerIsAGuestOrACustomer(): void
    {
        $guest = CartOwner::guest();
        $customer = CartOwner::customer('kc-sub-1');

        self::assertTrue($guest->isGuest());
        self::assertNull($guest->customerId());
        self::assertFalse($customer->isGuest());
        self::assertSame('kc-sub-1', $customer->customerId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCustomerIds(): iterable
    {
        yield 'empty' => [''];
        yield 'longer than 255 characters' => [str_repeat('a', 256)];
    }

    #[DataProvider('invalidCustomerIds')]
    public function testCustomerIdMustBeOneTo255Characters(string $customerId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CartOwner::customer($customerId);
    }

    public function testCustomerIdOf255CharactersIsValid(): void
    {
        self::assertSame(str_repeat('a', 255), CartOwner::customer(str_repeat('a', 255))->customerId());
    }

    public function testQuantityIsFrom1To99(): void
    {
        self::assertSame(1, Quantity::of(1)->value());
        self::assertSame(99, Quantity::of(99)->value());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function quantitiesOutOfRange(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'hundred' => [100];
    }

    #[DataProvider('quantitiesOutOfRange')]
    public function testQuantityOutOfRangeIsInvalidInput(int $value): void
    {
        try {
            Quantity::of($value);
            self::fail('Expected InvalidQuantity.');
        } catch (InvalidQuantity $invalid) {
            self::assertSame('Ilość musi wynosić od 1 do 99 szt.', $invalid->getMessage());
        }
    }

    public function testMoneyAddsAndMultipliesInGrosze(): void
    {
        $price = Money::of(1999, Currency::PLN);

        self::assertSame(5997, $price->multiply(3)->amount());
        self::assertSame(2000, $price->add(Money::of(1, Currency::PLN))->amount());
        self::assertSame(0, Money::zero(Currency::PLN)->amount());
    }

    public function testTheLargestCartTotalFitsAnInteger(): void
    {
        $line = Money::of(99_999_999, Currency::PLN)->multiply(99);
        $total = Money::zero(Currency::PLN);
        for ($i = 0; $i < 50; ++$i) {
            $total = $total->add($line);
        }

        self::assertSame(50 * 99 * 99_999_999, $total->amount());
    }

    public function testMoneyIsNeverNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of(-1, Currency::PLN);
    }

    public function testOfferAvailabilityIsNeverNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductOffer(true, -1);
    }
}
