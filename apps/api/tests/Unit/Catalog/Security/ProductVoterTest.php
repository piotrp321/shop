<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Security;

use App\Backoffice\Infrastructure\Security\StaffUser;
use App\Catalog\Infrastructure\Security\ProductVoter;
use App\Customer\Infrastructure\Security\CustomerUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ProductVoterTest extends TestCase
{
    private const string STAFF_ID = 'f05dcc92-3f9f-41ba-a905-5325ece4a239';

    /**
     * @return iterable<string, array{list<string>, int, int}> roles, view, manage
     */
    public static function staffRoles(): iterable
    {
        yield 'admin' => [['admin'], VoterInterface::ACCESS_GRANTED, VoterInterface::ACCESS_GRANTED];
        yield 'marketing' => [['marketing'], VoterInterface::ACCESS_GRANTED, VoterInterface::ACCESS_GRANTED];
        yield 'support and marketing' => [['support', 'marketing'], VoterInterface::ACCESS_GRANTED, VoterInterface::ACCESS_GRANTED];
        yield 'support' => [['support'], VoterInterface::ACCESS_GRANTED, VoterInterface::ACCESS_DENIED];
        yield 'warehouse' => [['warehouse'], VoterInterface::ACCESS_GRANTED, VoterInterface::ACCESS_DENIED];
        yield 'no staff role' => [[], VoterInterface::ACCESS_GRANTED, VoterInterface::ACCESS_DENIED];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoles')]
    public function testEveryStaffMemberViewsAndOnlyAdminAndMarketingManage(array $roles, int $view, int $manage): void
    {
        $user = new StaffUser(self::STAFF_ID, 'staff', $roles);

        self::assertSame($view, $this->vote($user, ProductVoter::VIEW_PRODUCTS));
        self::assertSame($manage, $this->vote($user, ProductVoter::MANAGE_PRODUCTS));
    }

    public function testACustomerIsDeniedBoth(): void
    {
        $customer = new CustomerUser('58276b79-2248-4817-8b28-5dbbb4f343ab', 'jan@example.com', 'Jan');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($customer, ProductVoter::VIEW_PRODUCTS));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($customer, ProductVoter::MANAGE_PRODUCTS));
    }

    public function testAnonymousIsDeniedBoth(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, (new ProductVoter())->vote(new NullToken(), null, [ProductVoter::VIEW_PRODUCTS]));
        self::assertSame(VoterInterface::ACCESS_DENIED, (new ProductVoter())->vote(new NullToken(), null, [ProductVoter::MANAGE_PRODUCTS]));
    }

    public function testOtherAttributesAreNotVotedOn(): void
    {
        $token = new UsernamePasswordToken(new StaffUser(self::STAFF_ID, 'admin', ['admin']), 'admin', ['ROLE_STAFF']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new ProductVoter())->vote($token, null, ['CHANGE_PRICE']));
    }

    private function vote(UserInterface $user, string $attribute): int
    {
        return (new ProductVoter())->vote(new UsernamePasswordToken($user, 'admin', $user->getRoles()), null, [$attribute]);
    }
}
