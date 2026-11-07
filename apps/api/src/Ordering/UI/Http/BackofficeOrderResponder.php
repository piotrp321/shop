<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Domain\StaffTransition;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The answer of the detail, the status change and the refund completion: the order with what the staff member of the
 * token may do with it now. The transitions are the ones the status allows (statusTransitions) that the voter grants,
 * the refund may be completed when it is to do and the voter grants it; the rule of the roles stays in the voter.
 */
final readonly class BackofficeOrderResponder
{
    public function __construct(private AuthorizationCheckerInterface $authorization)
    {
    }

    public function respond(BackofficeOrderView $order): JsonResponse
    {
        $allowed = array_values(array_filter(
            $order->statusTransitions,
            fn (string $transition): bool => $this->authorization->isGranted(BackofficeOrderAttributes::CHANGE_ORDER_STATUS, StaffTransition::from($transition)),
        ));
        $canCompleteRefund = null !== $order->refund && null === $order->refund->completedAt
            && $this->authorization->isGranted(BackofficeOrderAttributes::COMPLETE_REFUND);

        $response = new JsonResponse(BackofficeOrderJson::order($order, $allowed, $canCompleteRefund));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
