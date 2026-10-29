<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use App\Backoffice\Infrastructure\Security\StaffUser;
use App\Backoffice\Infrastructure\Security\StaffUserProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class StaffUserProviderTest extends TestCase
{
    private const string SUB = 'f05dcc92-3f9f-41ba-a905-5325ece4a239';

    public function testBuildsTheStaffMemberFromTheClaims(): void
    {
        $user = $this->load(['preferred_username' => 'admin', 'realm_access' => ['roles' => ['admin', 'offline_access', 'uma_authorization']]]);

        self::assertTrue((new \ReflectionClass($user))->implementsInterface(AuthenticatedStaffMember::class));
        self::assertSame(self::SUB, $user->id());
        self::assertSame(self::SUB, $user->getUserIdentifier());
        self::assertSame('admin', $user->username());
        self::assertSame(['admin'], $user->staffRoles());
        self::assertSame(['ROLE_STAFF'], $user->getRoles());
    }

    /**
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function roleClaims(): iterable
    {
        yield 'every known role, in a fixed order' => [['marketing', 'warehouse', 'support', 'admin'], ['admin', 'support', 'warehouse', 'marketing']];
        yield 'unknown roles dropped' => [['default-roles-backoffice', 'support', 'superuser'], ['support']];
        yield 'duplicates removed' => [['support', 'support'], ['support']];
        yield 'case matters' => [['Admin', 'ROLE_ADMIN'], []];
        yield 'no roles' => [[], []];
        yield 'roles not a list' => ['admin', []];
        yield 'non-string roles ignored' => [[['admin'], 1, null, 'warehouse'], ['warehouse']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('roleClaims')]
    public function testKeepsOnlyTheKnownStaffRolesAndAlwaysGrantsRoleStaff(mixed $roles, array $expected): void
    {
        $user = $this->load(['preferred_username' => 'support', 'realm_access' => ['roles' => $roles]]);

        self::assertSame($expected, $user->staffRoles());
        self::assertSame(['ROLE_STAFF'], $user->getRoles());
    }

    public function testMissingRealmAccessGivesNoStaffRoles(): void
    {
        $user = $this->load(['preferred_username' => 'support']);

        self::assertSame([], $user->staffRoles());
        self::assertSame(['ROLE_STAFF'], $user->getRoles());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableClaims(): iterable
    {
        yield 'no username' => [['realm_access' => ['roles' => ['admin']]]];
        yield 'empty username' => [['preferred_username' => '', 'realm_access' => ['roles' => ['admin']]]];
        yield 'username not a string' => [['preferred_username' => ['admin']]];
        yield 'no claims at all' => [[]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('unusableClaims')]
    public function testUnusableClaimsAreRejected(array $claims): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->load($claims);
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $this->expectException(UserNotFoundException::class);

        (new StaffUserProvider())->loadUserByIdentifier('', ['preferred_username' => 'admin']);
    }

    public function testSupportsOnlyStaffUsers(): void
    {
        $provider = new StaffUserProvider();

        self::assertTrue($provider->supportsClass(StaffUser::class));
        self::assertFalse($provider->supportsClass(InMemoryUser::class));
        $this->expectException(UnsupportedUserException::class);
        $provider->refreshUser(new InMemoryUser('x', null));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function load(array $claims): StaffUser
    {
        return (new StaffUserProvider())->loadUserByIdentifier(self::SUB, ['sub' => self::SUB, ...$claims]);
    }
}
