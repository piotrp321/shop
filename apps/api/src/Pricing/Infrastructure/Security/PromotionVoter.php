<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Security;

use App\Backoffice\Application\Security\AuthenticatedStaffMember;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * An administrator and marketing plan, change and delete promotions (Q1); every staff member reads them (the firewall
 * of the panel). Decided on the staff roles of the backoffice token, through the security contract of Backoffice.
 *
 * @extends Voter<string, null>
 */
final class PromotionVoter extends Voter
{
    public const string MANAGE_PROMOTIONS = 'MANAGE_PROMOTIONS';

    private const array MANAGERS = ['admin', 'marketing'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE_PROMOTIONS === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof AuthenticatedStaffMember && [] !== array_intersect(self::MANAGERS, $user->staffRoles());
    }
}
