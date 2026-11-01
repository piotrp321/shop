<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Only an administrator changes prices; every staff member reads them. Decided on the staff roles of the
 * backoffice token, through the security contract of Backoffice.
 *
 * @extends Voter<string, null>
 */
final class PriceVoter extends Voter
{
    public const string CHANGE_PRICE = 'CHANGE_PRICE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::CHANGE_PRICE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof AuthenticatedStaffMember && \in_array('admin', $user->staffRoles(), true);
    }
}
