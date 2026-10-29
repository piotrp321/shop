<?php

declare(strict_types=1);

namespace App\Customer\Application\Security;

/**
 * The customer who sent the request, as identified by a valid access token of the `shop` realm.
 */
interface AuthenticatedCustomer
{
    /**
     * The `sub` of the token: the stable customer id, also the owner of the customer's resources.
     */
    public function id(): string;

    public function email(): string;

    /**
     * Full name, empty when the account has none.
     */
    public function name(): string;
}
