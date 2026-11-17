<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Identity\DemoUuid;
use App\DemoData\Identity\StaffRoles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StaffRolesTest extends TestCase
{
    private const string NAMESPACE = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function boundaries(): iterable
    {
        yield 'first admin' => [1, 'admin'];
        yield 'last admin' => [10, 'admin'];
        yield 'first support' => [11, 'support'];
        yield 'last support' => [210, 'support'];
        yield 'first warehouse' => [211, 'warehouse'];
        yield 'last warehouse' => [410, 'warehouse'];
        yield 'first marketing' => [411, 'marketing'];
        yield 'last marketing' => [500, 'marketing'];
        yield 'the cycle starts again' => [501, 'admin'];
        yield 'second cycle support' => [511, 'support'];
        yield 'second cycle last' => [1000, 'marketing'];
    }

    #[DataProvider('boundaries')]
    public function testTheRoleIsAFunctionOfTheAccountNumberOnly(int $number, string $role): void
    {
        self::assertSame($role, StaffRoles::roleOf($number));
    }

    public function testEveryFiveHundredNumbersHaveTheSpecifiedMix(): void
    {
        $counts = array_count_values(array_map(StaffRoles::roleOf(...), range(1, 500)));

        self::assertSame(['admin' => 10, 'support' => 200, 'warehouse' => 200, 'marketing' => 90], $counts);
    }

    public function testNumbersStartAtOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StaffRoles::roleOf(0);
    }

    public function testTheAdminsOfASmallStaffAreTheFirstTenNumbers(): void
    {
        self::assertSame(range(1, 10), StaffRoles::adminNumbers(20));
        self::assertSame(range(1, 10), StaffRoles::adminNumbers(500));
        self::assertSame([1, 2, 3], StaffRoles::adminNumbers(3));
        self::assertSame([...range(1, 10), 501, 502], StaffRoles::adminNumbers(502));
    }

    public function testThereIsNoAdminWithoutStaff(): void
    {
        self::assertSame([], StaffRoles::adminNumbers(0));
    }

    public function testTheActorIdIsTheUuidV5OfTheStaffKeyInTheNamespaceOfTheProfile(): void
    {
        self::assertSame(DemoUuid::v5(self::NAMESPACE, 'staff:7'), StaffRoles::actorId(self::NAMESPACE, 7));
        self::assertNotSame(StaffRoles::actorId(self::NAMESPACE, 7), StaffRoles::actorId(self::NAMESPACE, 8));
    }

    public function testTheActorNameIsReadableAndStable(): void
    {
        self::assertSame(StaffRoles::actorName(7), StaffRoles::actorName(7));
        self::assertNotSame('', trim(StaffRoles::actorName(7)));
        self::assertNotSame(StaffRoles::actorName(7), StaffRoles::actorName(8));
    }
}
