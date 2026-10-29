<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dispatches on the real (sync) command bus, so the exceptions reach the subscriber wrapped in HandlerFailedException.
 */
#[AsController]
final readonly class TestMessengerController
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    #[Route('/_test/handler/{failure}', methods: ['GET'])]
    public function dispatch(string $failure): Response
    {
        $this->commandBus->dispatch(new TestMessage($failure));

        return new Response();
    }
}
