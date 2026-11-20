<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Security;

use App\Backoffice\Infrastructure\Security\StaffUser;
use App\Customer\Infrastructure\Security\CustomerUser;
use App\Delivery\Infrastructure\Security\DeliveryVoter;
use App\Delivery\UI\Http\DeliveryAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class DeliveryVoterTest extends TestCase
{
    private const string STAFF_ID = 'f05dcc92-3f9f-41ba-a905-5325ece4a239';

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function staffRoles(): iterable
    {
        yield 'admin' => [['admin'], VoterInterface::ACCESS_GRANTED];
        yield 'admin among others' => [['support', 'admin'], VoterInterface::ACCESS_GRANTED];
        yield 'support' => [['support'], VoterInterface::ACCESS_DENIED];
        yield 'warehouse' => [['warehouse'], VoterInterface::ACCESS_DENIED];
        yield 'marketing' => [['marketing'], VoterInterface::ACCESS_DENIED];
        yield 'every other role' => [['support', 'warehouse', 'marketing'], VoterInterface::ACCESS_DENIED];
        yield 'admin in another case' => [['Admin'], VoterInterface::ACCESS_DENIED];
        yield 'no staff role' => [[], VoterInterface::ACCESS_DENIED];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoles')]
    public function testOnlyAnAdministratorManagesDeliveryMethods(array $roles, int $expected): void
    {
        self::assertSame($expected, $this->vote(new StaffUser(self::STAFF_ID, 'staff', $roles)));
    }

    public function testACustomerIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new CustomerUser('58276b79-2248-4817-8b28-5dbbb4f343ab', 'jan@example.com', 'Jan')));
    }

    public function testAnonymousIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, new DeliveryVoter()->vote(new NullToken(), null, [DeliveryAttributes::MANAGE_DELIVERY_METHODS]));
    }

    public function testOtherAttributesAreNotVotedOn(): void
    {
        $token = new UsernamePasswordToken(new StaffUser(self::STAFF_ID, 'admin', ['admin']), 'admin', ['ROLE_STAFF']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, new DeliveryVoter()->vote($token, null, ['MANAGE_DISCOUNT_CODES']));
    }

    private function vote(UserInterface $user): int
    {
        return new DeliveryVoter()->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), null, [DeliveryAttributes::MANAGE_DELIVERY_METHODS]);
    }
}
