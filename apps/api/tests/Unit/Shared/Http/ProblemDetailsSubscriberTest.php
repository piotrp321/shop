<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\ExtendedProblem;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Domain\Exception\TypedProblem;
use App\Shared\Domain\Exception\Unavailable;
use App\Shared\Infrastructure\Http\ProblemDetailsSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

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

    public function testAnExtendedProblemAddsItsMembers(): void
    {
        $problem = $this->problemOf(new class('Overlap.') extends \DomainException implements Conflict, TypedProblem, ExtendedProblem {
            public function problemType(): string
            {
                return 'tag:example.com,2026:problems/overlap';
            }

            public function problemExtensions(): array
            {
                return ['conflictingIds' => ['a', 'b']];
            }
        });

        self::assertSame(
            ['type' => 'tag:example.com,2026:problems/overlap', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Overlap.', 'conflictingIds' => ['a', 'b']],
            $problem,
        );
    }

    public function testAnExtensionNeverReplacesAMemberOfTheProblem(): void
    {
        $problem = $this->problemOf(new HandlerFailedException(new Envelope(new \stdClass()), [new class('Overlap.') extends \DomainException implements Conflict, ExtendedProblem {
            public function problemExtensions(): array
            {
                return ['type' => 'x', 'title' => 'x', 'status' => 200, 'detail' => 'x', 'instance' => 'x', 'violations' => [], 'extra' => 1];
            }
        }]));

        self::assertSame(['type' => 'about:blank', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Overlap.', 'extra' => 1], $problem);
    }

    public function testAServerErrorHasNoExtensions(): void
    {
        $problem = $this->problemOf(new class('Down.') extends \RuntimeException implements Unavailable, ExtendedProblem {
            public function problemExtensions(): array
            {
                return ['secret' => 'x'];
            }
        });

        self::assertSame(['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503], $problem);
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
     * The fixed texts of framework exceptions (spec: docs/features/api-input-contract/spec.md, section A); the `title`
     * stays the phrase of the HTTP status.
     *
     * @return iterable<string, array{int, string, string}>
     */
    public static function genericDetails(): iterable
    {
        yield '400' => [400, 'Bad Request', 'Żądanie jest nieprawidłowe.'];
        yield '401' => [401, 'Unauthorized', 'Wymagane jest uwierzytelnienie.'];
        yield '403' => [403, 'Forbidden', 'Brak dostępu.'];
        yield '404' => [404, 'Not Found', 'Nie znaleziono zasobu.'];
        yield '405' => [405, 'Method Not Allowed', 'Metoda nie jest dozwolona.'];
        yield '406' => [406, 'Not Acceptable', 'Żądana reprezentacja nie jest dostępna.'];
        yield '409' => [409, 'Conflict', 'Żądanie jest sprzeczne z bieżącym stanem.'];
        yield '415' => [415, 'Unsupported Media Type', 'Nieobsługiwany typ treści.'];
        yield '422' => [422, 'Unprocessable Content', 'Żądanie jest nieprawidłowe.'];
        yield '429' => [429, 'Too Many Requests', 'Zbyt wiele żądań.'];
        yield 'a status without a text of its own' => [418, "I'm a teapot", 'Nie można przetworzyć żądania.'];
    }

    #[DataProvider('genericDetails')]
    public function testAFrameworkExceptionHasTheFixedTextOfItsStatus(int $status, string $title, string $detail): void
    {
        $problem = $this->problemOf(new HttpException($status, 'internal secret'));

        self::assertSame(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail], $problem);
    }

    public function testAViolationIdenticalToAnEarlierOneIsLeftOut(): void
    {
        $problem = $this->problemOf(self::validationFailure([
            ['productId', 'Identyfikator produktu musi być poprawnym UUID.'],
            ['productId', 'Identyfikator produktu musi być poprawnym UUID.'],
            ['q', 'Szukana fraza jest za krótka.'],
            ['productId', 'Identyfikator produktu musi być poprawnym UUID.'],
        ]));

        self::assertSame([
            ['propertyPath' => 'productId', 'title' => 'Identyfikator produktu musi być poprawnym UUID.'],
            ['propertyPath' => 'q', 'title' => 'Szukana fraza jest za krótka.'],
        ], $problem['violations']);
    }

    public function testDifferentViolationsOfOneFieldAndOneTextAtTwoFieldsAllStay(): void
    {
        $problem = $this->problemOf(self::validationFailure([
            ['q', 'Filtr produktu nie łączy się z wyszukiwaniem.'],
            ['q', 'Szukana fraza jest za krótka.'],
            ['category', 'Ta wartość nie powinna być pusta.'],
            ['q', 'Ta wartość nie powinna być pusta.'],
        ]));

        self::assertSame([
            ['propertyPath' => 'q', 'title' => 'Filtr produktu nie łączy się z wyszukiwaniem.'],
            ['propertyPath' => 'q', 'title' => 'Szukana fraza jest za krótka.'],
            ['propertyPath' => 'category', 'title' => 'Ta wartość nie powinna być pusta.'],
            ['propertyPath' => 'q', 'title' => 'Ta wartość nie powinna być pusta.'],
        ], $problem['violations']);
    }

    /**
     * @param list<array{string, string}> $violations property path and message of each violation
     */
    private static function validationFailure(array $violations): ValidationFailedException
    {
        $list = new ConstraintViolationList();
        foreach ($violations as [$path, $message]) {
            $list->add(new ConstraintViolation($message, null, [], null, $path, null));
        }

        return new ValidationFailedException(null, $list);
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
