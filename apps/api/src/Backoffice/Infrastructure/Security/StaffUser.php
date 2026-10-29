<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A staff member authenticated by an access token of the `backoffice` realm. Built from the token claims on every
 * request. Every staff member has ROLE_STAFF; what they may do depends on their staff roles, checked by voters.
 */
final readonly class StaffUser implements UserInterface, AuthenticatedStaffMember
{
    public const string ROLE = 'ROLE_STAFF';

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
        return [self::ROLE];
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
