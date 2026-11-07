<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Security;

use App\Backoffice\Infrastructure\Security\StaffUser;
use App\Customer\Infrastructure\Security\CustomerUser;
use App\Ordering\Domain\StaffTransition;
use App\Ordering\Infrastructure\Security\OrderVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The rule of the roles of the panel for orders (P6): admin, support and warehouse see orders; admin and warehouse
 * ship and deliver; admin and support cancel, register returns and complete refunds; marketing nothing.
 */
final class OrderVoterTest extends TestCase
{
    private const string STAFF_ID = 'f05dcc92-3f9f-41ba-a905-5325ece4a239';

    private const int GRANTED = VoterInterface::ACCESS_GRANTED;
    private const int DENIED = VoterInterface::ACCESS_DENIED;

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function viewers(): iterable
    {
        yield 'admin' => [['admin'], self::GRANTED];
        yield 'support' => [['support'], self::GRANTED];
        yield 'warehouse' => [['warehouse'], self::GRANTED];
        yield 'marketing' => [['marketing'], self::DENIED];
        yield 'marketing with support' => [['marketing', 'support'], self::GRANTED];
        yield 'no staff role' => [[], self::DENIED];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('viewers')]
    public function testAdminSupportAndWarehouseSeeOrders(array $roles, int $expected): void
    {
        self::assertSame($expected, $this->vote($this->staff($roles), OrderVoter::VIEW_ORDERS));
    }

    /**
     * @return iterable<string, array{list<string>, StaffTransition, int}>
     */
    public static function transitions(): iterable
    {
        foreach (StaffTransition::cases() as $transition) {
            yield 'admin '.$transition->value => [['admin'], $transition, self::GRANTED];
            yield 'marketing '.$transition->value => [['marketing'], $transition, self::DENIED];
            yield 'no staff role '.$transition->value => [[], $transition, self::DENIED];
        }
        yield 'warehouse ship' => [['warehouse'], StaffTransition::Ship, self::GRANTED];
        yield 'warehouse deliver' => [['warehouse'], StaffTransition::Deliver, self::GRANTED];
        yield 'warehouse cancel' => [['warehouse'], StaffTransition::Cancel, self::DENIED];
        yield 'warehouse return' => [['warehouse'], StaffTransition::Return, self::DENIED];
        yield 'support ship' => [['support'], StaffTransition::Ship, self::DENIED];
        yield 'support deliver' => [['support'], StaffTransition::Deliver, self::DENIED];
        yield 'support cancel' => [['support'], StaffTransition::Cancel, self::GRANTED];
        yield 'support return' => [['support'], StaffTransition::Return, self::GRANTED];
        yield 'support and warehouse ship' => [['support', 'warehouse'], StaffTransition::Ship, self::GRANTED];
        yield 'support and warehouse cancel' => [['support', 'warehouse'], StaffTransition::Cancel, self::GRANTED];
        yield 'warehouse and marketing cancel' => [['warehouse', 'marketing'], StaffTransition::Cancel, self::DENIED];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('transitions')]
    public function testEachTransitionHasItsRoles(array $roles, StaffTransition $transition, int $expected): void
    {
        self::assertSame($expected, $this->vote($this->staff($roles), OrderVoter::CHANGE_ORDER_STATUS, $transition));
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function refunders(): iterable
    {
        yield 'admin' => [['admin'], self::GRANTED];
        yield 'support' => [['support'], self::GRANTED];
        yield 'warehouse' => [['warehouse'], self::DENIED];
        yield 'marketing' => [['marketing'], self::DENIED];
        yield 'warehouse and marketing' => [['warehouse', 'marketing'], self::DENIED];
        yield 'no staff role' => [[], self::DENIED];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('refunders')]
    public function testAdminAndSupportCompleteRefunds(array $roles, int $expected): void
    {
        self::assertSame($expected, $this->vote($this->staff($roles), OrderVoter::COMPLETE_REFUND));
    }

    /**
     * A status change is decided for a transition only: without one (or with its name as a string) the voter abstains,
     * and a decision of nothing but abstentions denies.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function notATransition(): iterable
    {
        yield 'no subject' => [null];
        yield 'the name of a transition' => ['ship'];
        yield 'another object' => [new \stdClass()];
    }

    #[DataProvider('notATransition')]
    public function testAStatusChangeWithoutATransitionIsNotVotedOn(mixed $subject): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->staff(['admin']), OrderVoter::CHANGE_ORDER_STATUS, $subject));
    }

    public function testViewingAndCompletingARefundHaveNoSubject(): void
    {
        $admin = $this->staff(['admin']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($admin, OrderVoter::VIEW_ORDERS, StaffTransition::Ship));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($admin, OrderVoter::COMPLETE_REFUND, StaffTransition::Cancel));
    }

    public function testACustomerIsDeniedEverything(): void
    {
        $customer = new CustomerUser('58276b79-2248-4817-8b28-5dbbb4f343ab', 'jan@example.com', 'Jan');

        self::assertSame(self::DENIED, $this->vote($customer, OrderVoter::VIEW_ORDERS));
        self::assertSame(self::DENIED, $this->vote($customer, OrderVoter::CHANGE_ORDER_STATUS, StaffTransition::Ship));
        self::assertSame(self::DENIED, $this->vote($customer, OrderVoter::COMPLETE_REFUND));
    }

    public function testAnonymousIsDeniedEverything(): void
    {
        $voter = new OrderVoter();

        self::assertSame(self::DENIED, $voter->vote(new NullToken(), null, [OrderVoter::VIEW_ORDERS]));
        self::assertSame(self::DENIED, $voter->vote(new NullToken(), StaffTransition::Cancel, [OrderVoter::CHANGE_ORDER_STATUS]));
        self::assertSame(self::DENIED, $voter->vote(new NullToken(), null, [OrderVoter::COMPLETE_REFUND]));
    }

    public function testOtherAttributesAreNotVotedOn(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($this->staff(['admin']), 'MANAGE_STOCK'));
    }

    /**
     * @param list<string> $roles
     */
    private function staff(array $roles): StaffUser
    {
        return new StaffUser(self::STAFF_ID, 'anna', $roles);
    }

    private function vote(UserInterface $user, string $attribute, mixed $subject = null): int
    {
        return new OrderVoter()->vote(new UsernamePasswordToken($user, 'admin', $user->getRoles()), $subject, [$attribute]);
    }
}
