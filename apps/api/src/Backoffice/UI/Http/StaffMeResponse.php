<?php

declare(strict_types=1);

namespace App\Backoffice\UI\Http;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The staff member identified by the access token.')]
final readonly class StaffMeResponse
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[OA\Property(description: 'Staff member id, the `sub` of the token.', example: 'f05dcc92-3f9f-41ba-a905-5325ece4a239')]
        public string $id,
        #[OA\Property(example: 'admin')]
        public string $username,
        #[OA\Property(
            description: 'Staff roles, without any other realm role.',
            type: 'array',
            items: new OA\Items(type: 'string', enum: ['admin', 'support', 'warehouse', 'marketing']),
            example: ['admin'],
        )]
        public array $roles,
    ) {
    }

    public static function of(AuthenticatedStaffMember $staffMember): self
    {
        return new self($staffMember->id(), $staffMember->username(), $staffMember->staffRoles());
    }
}
