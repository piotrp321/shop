<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\Exception\NotFound;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Throws what the message asks for, to test how failures from a sync bus handler become responses.
 */
#[AsMessageHandler]
final class TestMessageHandler
{
    public function __invoke(TestMessage $message): void
    {
        throw match ($message->failure) {
            'project' => new class('Category not found.') extends \RuntimeException implements NotFound {},
            'framework' => new BadRequestHttpException('internal secret'),
            default => new \RuntimeException('internal secret'),
        };
    }
}
