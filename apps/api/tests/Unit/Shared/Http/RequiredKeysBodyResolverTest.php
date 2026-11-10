<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\UI\Http\PendingBody;
use App\Shared\UI\Http\RequiredKeysBody;
use App\Shared\UI\Http\RequiredKeysBodyResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

/**
 * How the body of a panel request with required keys is read: a missing key is one Polish "required" violation at the
 * key and never reaches the request class (not even as null), null and the other values are the request class's to
 * judge, malformed JSON is a 400 and another format a 415.
 */
final class RequiredKeysBodyResolverTest extends TestCase
{
    private const string REQUIRED = 'To pole jest wymagane.';

    public function testEveryKeyGivenIsReadAsJSONGivesItNullIncluded(): void
    {
        $body = $this->resolve('{"count": 3, "name": "Jesień", "note": null}');

        self::assertSame(3, $body->count);
        self::assertSame('Jesień', $body->name);
        self::assertNull($body->note);
    }

    public function testTheValuesAreNeverCast(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->resolve('{"count": "3", "name": "Jesień", "note": null}');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function bodiesWithMissingKeys(): iterable
    {
        yield 'no count' => ['{"name": "a", "note": null}', ['count']];
        yield 'no name' => ['{"count": 1, "note": null}', ['name']];
        yield 'no note, a key that accepts null' => ['{"count": 1, "name": "a"}', ['note']];
        yield 'no count and no note' => ['{"name": "a"}', ['count', 'note']];
        yield 'an empty object' => ['{}', ['count', 'name', 'note']];
        yield 'an empty body' => ['', ['count', 'name', 'note']];
        yield 'a list' => ['[3, "a", null]', ['count', 'name', 'note']];
        yield 'a number' => ['3', ['count', 'name', 'note']];
        yield 'a string' => ['"count"', ['count', 'name', 'note']];
        yield 'null' => ['null', ['count', 'name', 'note']];
        yield 'keys of no field only' => ['{"other": 1}', ['count', 'name', 'note']];
        yield 'a missing key beside a value that is no valid' => ['{"count": "x", "name": "a"}', ['note']];
    }

    /**
     * @param list<string> $paths
     */
    #[DataProvider('bodiesWithMissingKeys')]
    public function testAMissingKeyIsOnePolishRequiredViolationAtTheKey(string $content, array $paths): void
    {
        $violations = $this->violationsOf($content);

        self::assertSame(array_map(static fn (string $path): array => [$path, self::REQUIRED], $paths), $violations);
    }

    public function testNullIsNotAMissingKeyAndTheRequestClassJudgesIt(): void
    {
        $violations = $this->violationsOf('{"count": null, "name": "a", "note": null}');

        self::assertSame([['count', 'Podaj liczbę.']], $violations);
    }

    public function testAKeyOfNoFieldIsIgnored(): void
    {
        $body = $this->resolve('{"count": 3, "name": "a", "note": null, "other": [1]}');

        self::assertSame(3, $body->count);
    }

    public function testAnInvalidValueIsAViolationAtItsKey(): void
    {
        self::assertSame([['name', 'Podaj nazwę.']], $this->violationsOf('{"count": 3, "name": "", "note": null}'));
    }

    public function testMalformedJSONIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolve('{"count": 3,');
    }

    public function testAnotherFormatIsUnsupported(): void
    {
        $this->expectException(UnsupportedMediaTypeHttpException::class);

        $this->resolve('count=3&name=a', 'application/x-www-form-urlencoded');
    }

    public function testNoContentTypeIsUnsupported(): void
    {
        $this->expectException(UnsupportedMediaTypeHttpException::class);

        $this->resolve('{}', null);
    }

    public function testTheBodyIsReadAfterTheChecksOfTheControllerAttributes(): void
    {
        $resolver = self::resolver();
        $request = self::request('{not json', 'text/plain');

        $resolved = $resolver->resolve($request, self::argument());

        self::assertEquals([new PendingBody(RequiredKeysBodyFixture::class)], $resolved, 'resolving reads nothing, so a 403 of #[IsGranted] comes before the body');
        $listeners = RequiredKeysBodyResolver::getSubscribedEvents()[KernelEvents::CONTROLLER_ARGUMENTS];
        self::assertIsArray($listeners);
        self::assertLessThan(20, $listeners[1], 'after the listener of #[IsGranted] (priority 20)');
    }

    public function testAnArgumentWithoutTheAttributeIsLeftToOtherResolvers(): void
    {
        self::assertSame([], self::resolver()->resolve(self::request('{}', 'application/json'), new ArgumentMetadata('body', RequiredKeysBodyFixture::class, false, false, null)));
    }

    private function resolve(string $content, ?string $contentType = 'application/json'): RequiredKeysBodyFixture
    {
        $request = self::request($content, $contentType);
        $resolver = self::resolver();
        $event = new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), static fn (RequiredKeysBodyFixture $body): null => null, $resolver->resolve($request, self::argument()), $request, HttpKernelInterface::MAIN_REQUEST);

        $resolver->onKernelControllerArguments($event);

        $body = $event->getArguments()[0] ?? null;
        self::assertInstanceOf(RequiredKeysBodyFixture::class, $body);

        return $body;
    }

    private static function resolver(): RequiredKeysBodyResolver
    {
        return new RequiredKeysBodyResolver(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
    }

    private static function argument(): ArgumentMetadata
    {
        return new ArgumentMetadata('body', RequiredKeysBodyFixture::class, false, false, null, false, [new RequiredKeysBody()]);
    }

    /**
     * @return list<array{string, string}> the property path and the message of every violation
     */
    private function violationsOf(string $content): array
    {
        try {
            $this->resolve($content);
        } catch (UnprocessableEntityHttpException $unprocessable) {
            $failed = $unprocessable->getPrevious();
            self::assertInstanceOf(ValidationFailedException::class, $failed);

            $violations = [];
            foreach ($failed->getViolations() as $violation) {
                $violations[] = [$violation->getPropertyPath(), (string) $violation->getMessage()];
            }

            return $violations;
        }
        self::fail('A 422 was expected.');
    }

    private static function request(string $content, ?string $contentType): Request
    {
        return Request::create('/', 'POST', server: null === $contentType ? [] : ['CONTENT_TYPE' => $contentType], content: $content);
    }
}

/**
 * @internal the request class of the test: the way the panel's requests are, with mixed properties
 */
final readonly class RequiredKeysBodyFixture
{
    public function __construct(
        #[Assert\NotNull(message: 'Podaj liczbę.')]
        #[Assert\Type('integer', message: 'Podaj liczbę.')]
        public mixed $count,
        #[Assert\Sequentially([
            new Assert\Type('string', message: 'Podaj nazwę.'),
            new Assert\NotBlank(message: 'Podaj nazwę.'),
        ])]
        public mixed $name,
        public mixed $note,
    ) {
    }
}
