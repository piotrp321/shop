<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Domain\Exception\Unavailable;
use App\Shared\Infrastructure\Http\ProblemDetailsSubscriber;
use App\Shared\Infrastructure\Observability\ServerErrorReporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\Options;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * One channel for the HTTP errors (spec: docs/features/sentry/spec.md, D5): an exception is reported to Sentry when
 * the response it ends in is a server error, with the status of the one mapping that ProblemDetailsSubscriber renders.
 */
final class ServerErrorReporterTest extends TestCase
{
    /** @var list<array{Event, ?EventHint}> */
    private array $captured = [];

    #[DataProvider('serverErrors')]
    public function testAnExceptionThatEndsInAServerErrorIsReportedOnce(\Throwable $exception): void
    {
        $this->reporter()->onException($this->eventOf($exception));

        self::assertCount(1, $this->captured);
        self::assertSame($exception, $this->captured[0][1]?->exception);
        self::assertFalse($this->captured[0][1]->mechanism?->isHandled(), 'an error the API did not handle');
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function serverErrors(): iterable
    {
        yield '500' => [new \RuntimeException('internal')];
        yield 'a handler failure of two exceptions' => [new HandlerFailedException(new Envelope(new \stdClass()), [new \RuntimeException('a'), new \RuntimeException('b')])];
        yield 'Unavailable (503)' => [new class('Down.') extends \RuntimeException implements Unavailable {}];
    }

    #[DataProvider('clientErrors')]
    public function testAnExceptionThatEndsInAClientErrorIsNotReported(\Throwable $exception): void
    {
        $this->reporter()->onException($this->eventOf($exception));

        self::assertSame([], $this->captured);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function clientErrors(): iterable
    {
        yield '400' => [new ValidationFailedException('input', new ConstraintViolationList())];
        yield '401' => [new UnauthorizedHttpException('Bearer')];
        yield '403' => [new AccessDeniedHttpException()];
        yield '403 outside a firewall' => [new AccessDeniedException()];
        yield '404' => [new NotFoundHttpException()];
        yield '405' => [new MethodNotAllowedHttpException(['GET'])];
        yield '429' => [new TooManyRequestsHttpException()];
        yield 'NotFound' => [new class('Not found.') extends \RuntimeException implements NotFound {}];
        yield 'Conflict' => [new class('Taken.') extends \RuntimeException implements Conflict {}];
        yield 'InvalidInput' => [new class('Wrong.') extends \RuntimeException implements InvalidInput {}];
        yield 'a handler failure of one Conflict' => [new HandlerFailedException(new Envelope(new \stdClass()), [new class('Taken.') extends \RuntimeException implements Conflict {}])];
    }

    /**
     * ProblemDetailsSubscriber sets the response, which stops the propagation of the event: the reporter listens
     * just before it.
     */
    public function testTheReporterListensJustBeforeTheProblemDetails(): void
    {
        $reporter = ServerErrorReporter::getSubscribedEvents()[KernelEvents::EXCEPTION];
        $problems = ProblemDetailsSubscriber::getSubscribedEvents()[KernelEvents::EXCEPTION];
        self::assertIsArray($reporter);
        self::assertIsArray($problems);

        self::assertGreaterThan($problems[1], $reporter[1]);
        self::assertLessThan(0, $reporter[1], 'after the framework logged the exception');
    }

    private function reporter(): ServerErrorReporter
    {
        $client = $this->createStub(ClientInterface::class);
        $client->method('getOptions')->willReturn(new Options());
        $client->method('captureEvent')->willReturnCallback(function (Event $event, ?EventHint $hint = null, ?Scope $scope = null): EventId {
            $this->captured[] = [$event, $hint];

            return $event->getId();
        });

        return new ServerErrorReporter(new Hub($client));
    }

    private function eventOf(\Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $exception);
    }
}
