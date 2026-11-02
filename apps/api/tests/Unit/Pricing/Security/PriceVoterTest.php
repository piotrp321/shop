<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Security;

use App\Backoffice\Infrastructure\Security\StaffUser;
use App\Customer\Infrastructure\Security\CustomerUser;
use App\Pricing\Infrastructure\Security\PriceVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class PriceVoterTest extends TestCase
{
    private const string STAFF_ID = 'f05dcc92-3f9f-41ba-a905-5325ece4a239';

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function staffRoles(): iterable
    {
        yield 'admin' => [['admin'], VoterInterface::ACCESS_GRANTED];
        yield 'admin among others' => [['admin', 'marketing'], VoterInterface::ACCESS_GRANTED];
        yield 'marketing' => [['marketing'], VoterInterface::ACCESS_DENIED];
        yield 'support' => [['support'], VoterInterface::ACCESS_DENIED];
        yield 'warehouse' => [['warehouse'], VoterInterface::ACCESS_DENIED];
        yield 'all roles but admin' => [['support', 'warehouse', 'marketing'], VoterInterface::ACCESS_DENIED];
        yield 'no staff role' => [[], VoterInterface::ACCESS_DENIED];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoles')]
    public function testOnlyAnAdministratorMayChangeAPrice(array $roles, int $expected): void
    {
        self::assertSame($expected, $this->vote(new StaffUser(self::STAFF_ID, 'staff', $roles)));
    }

    public function testACustomerIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new CustomerUser('58276b79-2248-4817-8b28-5dbbb4f343ab', 'jan@example.com', 'Jan')));
    }

    public function testAnonymousIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, (new PriceVoter())->vote(new NullToken(), null, [PriceVoter::CHANGE_PRICE]));
    }

    public function testOtherAttributesAreNotVotedOn(): void
    {
        $token = new UsernamePasswordToken(new StaffUser(self::STAFF_ID, 'admin', ['admin']), 'admin', ['ROLE_STAFF']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new PriceVoter())->vote($token, null, ['VIEW_SYSTEM_STATUS']));
    }

    private function vote(UserInterface $user): int
    {
        $token = new UsernamePasswordToken($user, 'admin', $user->getRoles());

        return (new PriceVoter())->vote($token, null, [PriceVoter::CHANGE_PRICE]);
    }
}
