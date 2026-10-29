<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Security;

/**
 * The staff member who sent the request, as identified by a valid access token of the `backoffice` realm.
 */
interface AuthenticatedStaffMember
{
    /**
     * The `sub` of the token: the stable staff member id, recorded with the changes they make.
     */
    public function id(): string;

    public function username(): string;

    /**
     * Staff roles of the token (`admin`, `support`, `warehouse`, `marketing`), without any other realm role.
     *
     * @return list<string>
     */
    public function staffRoles(): array;
}
