<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Domain\Exception\ProblemException;
use App\Shared\Domain\Exception\Unavailable;
use App\Shared\Infrastructure\Http\ExceptionStatus;
use App\Shared\Infrastructure\Http\ProblemDetailsSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ExceptionStatusTest extends TestCase
{
    #[DataProvider('exceptions')]
    public function testTheStatusOfAnException(\Throwable $exception, int $status): void
    {
        self::assertSame($status, ExceptionStatus::of($exception));
    }

    /**
     * One mapping: the status is the status of the problem document that ProblemDetailsSubscriber renders.
     */
    #[DataProvider('exceptions')]
    public function testTheStatusIsTheOneOfTheRenderedProblem(\Throwable $exception, int $status): void
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $exception);

        (new ProblemDetailsSubscriber())->onException($event);

        self::assertSame(ExceptionStatus::of($exception), $event->getResponse()?->getStatusCode());
    }

    /**
     * @return iterable<string, array{\Throwable, int}>
     */
    public static function exceptions(): iterable
    {
        $validation = new ValidationFailedException('input', new ConstraintViolationList([new ConstraintViolation('Name is required.', null, [], 'input', 'name', null)]));

        yield 'NotFound' => [new class('Not found.') extends \RuntimeException implements NotFound {}, 404];
        yield 'Conflict' => [new class('Taken.') extends \RuntimeException implements Conflict {}, 409];
        yield 'InvalidInput' => [new class('Wrong.') extends \RuntimeException implements InvalidInput {}, 422];
        yield 'a bare ProblemException' => [new class('Wrong.') extends \RuntimeException implements ProblemException {}, 422];
        yield 'Unavailable' => [new class('Down.') extends \RuntimeException implements Unavailable {}, 503];
        yield 'a validation failure' => [$validation, 400];
        yield 'a validation failure of a request payload' => [new UnprocessableEntityHttpException('', $validation), 422];
        yield 'a validation failure of a query' => [new NotFoundHttpException('', $validation), 400];
        yield 'a validation failure in a server error' => [new ServiceUnavailableHttpException(30, '', $validation), 400];
        yield 'an authentication failure' => [new AuthenticationException(), 401];
        yield 'an access denied' => [new AccessDeniedException(), 403];
        yield 'a framework 404' => [new NotFoundHttpException(), 404];
        yield 'a framework 405' => [new MethodNotAllowedHttpException(['GET']), 405];
        yield 'a framework 429' => [new TooManyRequestsHttpException(), 429];
        yield 'a framework 503' => [new ServiceUnavailableHttpException(), 503];
        yield 'a project exception that is an HTTP exception too' => [new class('Not found.') extends \RuntimeException implements NotFound, HttpExceptionInterface {
            public function getStatusCode(): int
            {
                return 400;
            }

            /**
             * @return array<string, string>
             */
            public function getHeaders(): array
            {
                return [];
            }
        }, 404];
        yield 'a handler failure of one exception' => [new HandlerFailedException(new Envelope(new \stdClass()), [new class('Taken.') extends \RuntimeException implements Conflict {}]), 409];
        yield 'nested handler failures of one exception' => [new HandlerFailedException(new Envelope(new \stdClass()), [new HandlerFailedException(new Envelope(new \stdClass()), [$validation])]), 400];
        yield 'a handler failure of two exceptions' => [new HandlerFailedException(new Envelope(new \stdClass()), [$validation, new \RuntimeException('internal')]), 500];
        yield 'a framework exception wrapped in a plain one' => [new \RuntimeException('wrapper', 0, new NotFoundHttpException()), 500];
        yield 'any other exception' => [new \RuntimeException('internal'), 500];
        yield 'an error' => [new \TypeError('internal'), 500];
    }
}
