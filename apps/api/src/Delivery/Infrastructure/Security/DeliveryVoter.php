<?php

declare(strict_types=1);

namespace App\Delivery\Infrastructure\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Only an administrator reads and changes the delivery methods (P7, W1). Decided on the staff roles of the backoffice
 * token, through the security contract of Backoffice.
 *
 * @extends Voter<string, null>
 */
final class DeliveryVoter extends Voter
{
    public const string MANAGE_DELIVERY_METHODS = 'MANAGE_DELIVERY_METHODS';

    private const string MANAGER = 'admin';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE_DELIVERY_METHODS === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof AuthenticatedStaffMember && \in_array(self::MANAGER, $user->staffRoles(), true);
    }
}
