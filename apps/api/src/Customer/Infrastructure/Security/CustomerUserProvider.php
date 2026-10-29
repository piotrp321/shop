<?php

declare(strict_types=1);

namespace App\Customer\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Builds the customer from the claims of a verified access token (the `oidc` token handler passes them as
 * attributes). Nothing is stored: the token is the only source of the customer's identity.
 *
 * @implements AttributesBasedUserProviderInterface<CustomerUser>
 */
final class CustomerUserProvider implements AttributesBasedUserProviderInterface
{
    /**
     * @param array<array-key, mixed> $attributes
     */
    public function loadUserByIdentifier(string $identifier, array $attributes = []): CustomerUser
    {
        $email = $attributes['email'] ?? null;
        $name = $attributes['name'] ?? '';
        if ('' === $identifier || !\is_string($email) || '' === $email || !\is_string($name)) {
            // A token without usable claims; the message is never shown to the client.
            throw new UserNotFoundException('The access token does not identify a customer.');
        }

        return new CustomerUser($identifier, $email, $name);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        // The firewall is stateless, so users are never refreshed from a session.
        throw new UnsupportedUserException('Customers are not refreshed.');
    }

    public function supportsClass(string $class): bool
    {
        return CustomerUser::class === $class;
    }
}
