<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Domain\Exception\TypedProblem;
use App\Shared\Infrastructure\Http\ProblemDetailsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class ProblemDetailsSubscriberTest extends TestCase
{
    public function testAProblemWithATypeIsRenderedWithThatType(): void
    {
        $problem = $this->problemOf(new class('Taken.') extends \DomainException implements Conflict, TypedProblem {
            public function problemType(): string
            {
                return 'tag:example.com,2026:problems/taken';
            }
        });

        self::assertSame(409, $problem['status']);
        self::assertSame('tag:example.com,2026:problems/taken', $problem['type']);
        self::assertSame('Taken.', $problem['detail']);
    }

    public function testATypedProblemKeepsItsTypeWhenAHandlerWrapsIt(): void
    {
        $typed = new class('Taken.') extends \DomainException implements Conflict, TypedProblem {
            public function problemType(): string
            {
                return 'tag:example.com,2026:problems/taken';
            }
        };

        $problem = $this->problemOf(new HandlerFailedException(new Envelope(new \stdClass()), [$typed]));

        self::assertSame('tag:example.com,2026:problems/taken', $problem['type']);
    }

    public function testAProblemWithoutATypeIsAboutBlank(): void
    {
        foreach ([
            new class('Taken.') extends \DomainException implements Conflict {},
            new class('Missing.') extends \DomainException implements NotFound {},
            new class('Invalid.') extends \DomainException implements InvalidInput {},
        ] as $exception) {
            self::assertSame('about:blank', $this->problemOf($exception)['type']);
        }
    }

    public function testOtherExceptionsAreAboutBlank(): void
    {
        self::assertSame('about:blank', $this->problemOf(new \RuntimeException('x'))['type']);
    }

    /**
     * @return array<mixed>
     */
    private function problemOf(\Throwable $exception): array
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $exception);

        new ProblemDetailsSubscriber()->onException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);

        return $problem;
    }
}
