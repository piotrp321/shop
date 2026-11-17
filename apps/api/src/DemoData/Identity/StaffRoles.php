<?php

declare(strict_types=1);

namespace App\DemoData\Identity;

/**
 * The staff of the generated data (spec: "Pracownicy"): account numbers start at 1, and the role of an account is a
 * function of its number only, a fixed cycle of 500 numbers (10 `admin`, 200 `support`, 200 `warehouse`, 90 `marketing`),
 * never of the number of accounts. The step of the accounts (Keycloak) and every actor of the history (price changes,
 * order history) use this one function, so a number means the same role everywhere.
 *
 * The id of the actor is the id of the account: UUID v5 of `staff:<n>` in the namespace of the profile.
 */
final class StaffRoles
{
    public const int CYCLE = 500;
    /** The roles in the order of the cycle, with the number of numbers of each. */
    private const array CYCLE_ROLES = ['admin' => 10, 'support' => 200, 'warehouse' => 200, 'marketing' => 90];

    private function __construct()
    {
    }

    /**
     * @param int $number the number of the account, from 1
     *
     * @throws \InvalidArgumentException for a number below 1
     */
    public static function roleOf(int $number): string
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('The number of a staff account starts at 1.');
        }

        $position = ($number - 1) % self::CYCLE;
        foreach (self::CYCLE_ROLES as $role => $count) {
            if ($position < $count) {
                return $role;
            }
            $position -= $count;
        }

        throw new \LogicException('The roles of the cycle add up to the cycle.');
    }

    /**
     * The numbers of the `admin` accounts among the accounts 1 to `staff`, in ascending order.
     *
     * @return list<int>
     */
    public static function adminNumbers(int $staff): array
    {
        $numbers = [];
        for ($first = 1; $first <= $staff; $first += self::CYCLE) {
            for ($number = $first; $number < $first + self::CYCLE_ROLES['admin'] && $number <= $staff; ++$number) {
                $numbers[] = $number;
            }
        }

        return $numbers;
    }

    public static function actorId(string $namespace, int $number): string
    {
        return DemoUuid::v5($namespace, 'staff:'.$number);
    }

    /**
     * The name the history shows for the actor. It is a stand-in that depends on the number only; the account of the
     * staff member (step 4) carries the person of the same number.
     */
    public static function actorName(int $number): string
    {
        return 'Pracownik demo '.$number;
    }
}
