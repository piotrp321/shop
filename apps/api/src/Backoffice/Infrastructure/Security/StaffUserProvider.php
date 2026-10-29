<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Builds the staff member from the claims of a verified access token (the `oidc` token handler passes them as
 * attributes). Realm roles other than the staff roles (e.g. Keycloak's `offline_access`) are dropped.
 *
 * @implements AttributesBasedUserProviderInterface<StaffUser>
 */
final class StaffUserProvider implements AttributesBasedUserProviderInterface
{
    /** Staff roles in the order they are reported. */
    public const array STAFF_ROLES = ['admin', 'support', 'warehouse', 'marketing'];

    /**
     * @param array<array-key, mixed> $attributes
     */
    public function loadUserByIdentifier(string $identifier, array $attributes = []): StaffUser
    {
        $username = $attributes['preferred_username'] ?? null;
        if ('' === $identifier || !\is_string($username) || '' === $username) {
            // A token without usable claims; the message is never shown to the client.
            throw new UserNotFoundException('The access token does not identify a staff member.');
        }

        return new StaffUser($identifier, $username, $this->staffRoles($attributes['realm_access'] ?? null));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        // The firewall is stateless, so users are never refreshed from a session.
        throw new UnsupportedUserException('Staff members are not refreshed.');
    }

    public function supportsClass(string $class): bool
    {
        return StaffUser::class === $class;
    }

    /**
     * @return list<string>
     */
    private function staffRoles(mixed $realmAccess): array
    {
        $roles = \is_array($realmAccess) ? ($realmAccess['roles'] ?? null) : null;
        if (!\is_array($roles)) {
            return [];
        }

        return array_values(array_filter(self::STAFF_ROLES, static fn (string $role): bool => \in_array($role, $roles, true)));
    }
}
