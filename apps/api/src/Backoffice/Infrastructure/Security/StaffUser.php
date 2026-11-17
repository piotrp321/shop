<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A staff member authenticated by an access token of the `backoffice` realm. Built from the token claims on every
 * request. Only a member with at least one staff role has ROLE_STAFF, which every panel route but GET /admin/me
 * requires (config/packages/security.yaml); what they may do beyond that depends on their staff roles, checked by
 * voters. A member without a staff role can only read who they are.
 */
final readonly class StaffUser implements UserInterface, AuthenticatedStaffMember
{
    public const string ROLE = 'ROLE_STAFF';

    /** Staff roles in the order they are reported; the only list of them. */
    public const array STAFF_ROLES = ['admin', 'support', 'warehouse', 'marketing'];

    /**
     * @param non-empty-string $id
     * @param list<string>     $staffRoles
     */
    public function __construct(
        private string $id,
        private string $username,
        private array $staffRoles,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function staffRoles(): array
    {
        return $this->staffRoles;
    }

    public function getRoles(): array
    {
        return [] === array_intersect($this->staffRoles, self::STAFF_ROLES) ? [] : [self::ROLE];
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
