<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\UnpaidOrderIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whose unpaid orders an order counts to (ABUSE-LIMITS, L6, D6): a guest by the key of the email (without white space
 * at the edges, case or a `+…` part before the `@`), a customer by the `sub`, and never one for the other.
 */
final class UnpaidOrderIdentityTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function formsOfOneEmail(): iterable
    {
        yield 'as typed' => ['jan.kowalski@example.com'];
        yield 'upper case' => ['JAN.Kowalski@EXAMPLE.COM'];
        yield 'white space at the edges' => ['  jan.kowalski@example.com '];
        yield 'a +tag' => ['jan.kowalski+sklep@example.com'];
        yield 'an empty +tag' => ['jan.kowalski+@example.com'];
        yield 'a +tag with a + in it, upper case' => [' Jan.Kowalski+A+B@Example.com '];
    }

    #[DataProvider('formsOfOneEmail')]
    public function testEveryFormOfAnEmailOfAGuestHasTheSameKey(string $email): void
    {
        self::assertSame('email:jan.kowalski@example.com', UnpaidOrderIdentity::of(OrderOwner::guest(), EmailAddress::fromString($email))->key());
    }

    public function testOtherEmailsHaveOtherKeys(): void
    {
        $key = static fn (string $email): string => UnpaidOrderIdentity::of(OrderOwner::guest(), EmailAddress::fromString($email))->key();

        self::assertNotSame($key('jan.kowalski@example.com'), $key('jan.kowalsky@example.com'));
        self::assertNotSame($key('jan@example.com'), $key('jan@example.org'));
        self::assertNotSame($key('jan.kowalski@example.com'), $key('jankowalski@example.com'), 'dots are kept');
    }

    public function testPolishLettersAreInLowerCase(): void
    {
        self::assertSame('email:żółw@example.pl', UnpaidOrderIdentity::of(OrderOwner::guest(), EmailAddress::fromString('ŻÓŁW+x@Example.PL'))->key());
    }

    public function testAPlusInTheDomainIsKept(): void
    {
        self::assertSame('email:jan@ex+ample.com', UnpaidOrderIdentity::of(OrderOwner::guest(), EmailAddress::fromString('jan@ex+ample.com'))->key());
    }

    public function testACustomerCountsByTheSubAsItIsNotByTheEmail(): void
    {
        $identity = UnpaidOrderIdentity::of(OrderOwner::customer('Kc-Sub-1'), EmailAddress::fromString('jan@example.com'));

        self::assertSame('customer:Kc-Sub-1', $identity->key(), 'the sub of the token, case kept');
    }

    public function testTheEmailOfACustomerIsNotTheIdentityOfAGuestWithThatEmail(): void
    {
        $email = EmailAddress::fromString('jan@example.com');

        self::assertNotSame(UnpaidOrderIdentity::of(OrderOwner::guest(), $email)->key(), UnpaidOrderIdentity::of(OrderOwner::customer('jan@example.com'), $email)->key());
    }

    public function testTheLongestKeysFitTheirLimit(): void
    {
        $email = EmailAddress::fromString(str_repeat('a', 64).'@'.str_repeat('b', 189));
        $customer = OrderOwner::customer(str_repeat('c', OrderOwner::MAX_CUSTOMER_ID_LENGTH));

        self::assertLessThanOrEqual(UnpaidOrderIdentity::MAX_LENGTH, mb_strlen(UnpaidOrderIdentity::of(OrderOwner::guest(), $email)->key()));
        self::assertSame(UnpaidOrderIdentity::MAX_LENGTH, mb_strlen(UnpaidOrderIdentity::of($customer, $email)->key()));
    }
}
