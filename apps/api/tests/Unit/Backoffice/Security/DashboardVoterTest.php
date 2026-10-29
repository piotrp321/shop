<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Security;

use App\Backoffice\Infrastructure\Security\DashboardVoter;
use App\Backoffice\Infrastructure\Security\StaffUser;
use App\Customer\Infrastructure\Security\CustomerUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class DashboardVoterTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function staffRoles(): iterable
    {
        yield 'admin' => [['admin'], VoterInterface::ACCESS_GRANTED];
        yield 'admin among others' => [['admin', 'support'], VoterInterface::ACCESS_GRANTED];
        yield 'support' => [['support'], VoterInterface::ACCESS_DENIED];
        yield 'warehouse' => [['warehouse'], VoterInterface::ACCESS_DENIED];
        yield 'marketing' => [['marketing'], VoterInterface::ACCESS_DENIED];
        yield 'all roles but admin' => [['support', 'warehouse', 'marketing'], VoterInterface::ACCESS_DENIED];
        yield 'no staff role' => [[], VoterInterface::ACCESS_DENIED];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoles')]
    public function testStaffAccessDependsOnTheAdminRole(array $roles, int $expected): void
    {
        self::assertSame($expected, $this->vote(new StaffUser('f05dcc92-3f9f-41ba-a905-5325ece4a239', 'staff', $roles)));
    }

    public function testCustomerIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new CustomerUser('58276b79-2248-4817-8b28-5dbbb4f343ab', 'jan@example.com', 'Jan')));
    }

    public function testAnonymousIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, (new DashboardVoter())->vote(new NullToken(), null, [DashboardVoter::VIEW_SYSTEM_STATUS]));
    }

    public function testOtherAttributesAreNotVotedOn(): void
    {
        $token = new UsernamePasswordToken(new StaffUser('f05dcc92-3f9f-41ba-a905-5325ece4a239', 'admin', ['admin']), 'admin', ['ROLE_STAFF']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new DashboardVoter())->vote($token, null, ['SOMETHING_ELSE']));
    }

    private function vote(UserInterface $user): int
    {
        $token = new UsernamePasswordToken($user, 'admin', $user->getRoles());

        return (new DashboardVoter())->vote($token, null, [DashboardVoter::VIEW_SYSTEM_STATUS]);
    }
}
