<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Whose unpaid orders an order counts to besides its address (the limit of unpaid orders): a guest by the email,
 * without case, white space at the edges or a `+…` part before the `@` (`email:<key>`), a customer by the `sub` of the
 * token (`customer:<sub>`). The email of a customer does not count to the email of a guest, so a guest who types
 * the email of an account cannot block the account.
 */
final readonly class UnpaidOrderIdentity
{
    /** `customer:` and the longest `sub` (OrderOwner::MAX_CUSTOMER_ID_LENGTH); an email key is shorter. */
    public const int MAX_LENGTH = 9 + OrderOwner::MAX_CUSTOMER_ID_LENGTH;

    private function __construct(private string $key)
    {
    }

    public static function of(OrderOwner $owner, EmailAddress $email): self
    {
        $customerId = $owner->customerId();

        return null !== $customerId ? new self('customer:'.$customerId) : new self('email:'.self::emailKey($email));
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * The email in lower case without the part from the first `+` of the local part to the `@` (the last `@`: the
     * domain has none).
     */
    private static function emailKey(EmailAddress $email): string
    {
        $value = mb_strtolower(trim($email->toString()));
        $at = (int) strrpos($value, '@');
        $local = substr($value, 0, $at);
        $plus = strpos($local, '+');

        return (false === $plus ? $local : substr($local, 0, $plus)).substr($value, $at);
    }
}
