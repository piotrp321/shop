<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backoffice\Security;

use App\Backoffice\Infrastructure\Security\StaffUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StaffUserTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function staffRoles(): iterable
    {
        foreach (['admin', 'support', 'warehouse', 'marketing'] as $role) {
            yield $role => [[$role], ['ROLE_STAFF']];
        }
        yield 'every staff role' => [['admin', 'support', 'warehouse', 'marketing'], ['ROLE_STAFF']];
        yield 'no role' => [[], []];
        yield 'only names that are not staff roles' => [['offline_access', 'Admin', 'ROLE_STAFF', ''], []];
        yield 'a staff role among others' => [['offline_access', 'support'], ['ROLE_STAFF']];
    }

    /**
     * @param list<string> $staffRoles
     * @param list<string> $expected
     */
    #[DataProvider('staffRoles')]
    public function testRoleStaffNeedsAtLeastOneStaffRole(array $staffRoles, array $expected): void
    {
        self::assertSame($expected, (new StaffUser('f05dcc92-3f9f-41ba-a905-5325ece4a239', 'staff', $staffRoles))->getRoles());
    }
}
