<?php

declare(strict_types=1);

namespace App\Customer\Infrastructure\Security;

use App\Customer\Application\Security\AuthenticatedCustomer;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A customer authenticated by an access token of the `shop` realm. Built from the token claims on every request.
 */
final readonly class CustomerUser implements UserInterface, AuthenticatedCustomer
{
    public const string ROLE = 'ROLE_CUSTOMER';

    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private string $id,
        private string $email,
        private string $name,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): string
    {
        return $this->name;
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
