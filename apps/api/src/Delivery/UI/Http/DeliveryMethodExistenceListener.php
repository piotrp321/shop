<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * For a controller marked #[ExistingDeliveryMethod], looks the method of `{id}` up between the checks of the
 * attributes such as #[IsGranted] (priority -10000) and the reading of a #[RequiredKeysBody] (-10100), so the order of
 * the answers is 401, 403, 404, then 400, 415 and 422 of the body. The command looks the method up again.
 */
final readonly class DeliveryMethodExistenceListener implements EventSubscriberInterface
{
    public function __construct(private DeliveryMethodResponder $responder)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => ['onKernelControllerArguments', -10050]];
    }

    public function onKernelControllerArguments(ControllerArgumentsEvent $event): void
    {
        if ([] === $event->getAttributes(ExistingDeliveryMethod::class)) {
            return;
        }
        $id = $event->getRequest()->attributes->get('id');
        \assert(\is_string($id));
        $this->responder->find($id);
    }
}
