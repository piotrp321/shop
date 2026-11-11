<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Reads the JSON body of a panel request into the class of an argument marked #[RequiredKeysBody] and validates it,
 * like #[MapRequestPayload] with strict types (415 for another format, 400 for JSON that does not parse, 422 with the
 * violations of the request class) but with every constructor parameter a required key: a missing key, also one that
 * accepts null, is the violation "To pole jest wymagane." at the key, and the request class is not built, so a missing
 * key never reaches it (as null, which for some keys has a meaning of its own, or as anything else). Only the missing
 * keys are reported, the values are judged once the keys are all there. A key given as null is the request class's to
 * judge. The values are taken as JSON gives them, never cast; keys of no parameter are ignored, and a JSON value that
 * is no object (or an empty body) has none of the keys.
 *
 * The body is read late, like #[MapRequestPayload] does: resolving the argument only leaves a marker, and the
 * `kernel.controller_arguments` listener below (after ControllerAttributesListener, which runs the
 * checks of attributes such as #[IsGranted] at priority -10000) replaces it
 * with the request class. So the 403 of the attribute still comes before the 400, 415 and 422 of the body.
 */
#[AsTargetedValueResolver]
final readonly class RequiredKeysBodyResolver implements ValueResolverInterface, EventSubscriberInterface
{
    public const string REQUIRED = 'To pole jest wymagane.';

    public function __construct(private ValidatorInterface $validator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // The priority of #[MapRequestPayload]: after the checks of attributes such as #[IsGranted].
        return [KernelEvents::CONTROLLER_ARGUMENTS => ['onKernelControllerArguments', -10100]];
    }

    /**
     * @return list<PendingBody>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $class = $argument->getType();
        if ([] === $argument->getAttributesOfType(RequiredKeysBody::class, ArgumentMetadata::IS_INSTANCEOF) || null === $class || !class_exists($class)) {
            return [];
        }

        return [new PendingBody($class)];
    }

    public function onKernelControllerArguments(ControllerArgumentsEvent $event): void
    {
        $arguments = $event->getArguments();
        foreach ($arguments as $position => $argument) {
            if ($argument instanceof PendingBody) {
                $arguments[$position] = $this->read($event->getRequest(), $argument->class);
            }
        }
        $event->setArguments($arguments);
    }

    /**
     * @param class-string $class
     */
    private function read(Request $request, string $class): object
    {
        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException();
        }
        $data = self::decode($request->getContent());

        $keys = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->name, new \ReflectionClass($class)->getConstructor()?->getParameters() ?? []);
        $missing = array_values(array_filter($keys, static fn (string $key): bool => !\array_key_exists($key, $data)));
        if ([] !== $missing) {
            throw new UnprocessableEntityHttpException(previous: new ValidationFailedException($data, new ConstraintViolationList(array_map(static fn (string $key): ConstraintViolation => new ConstraintViolation(self::REQUIRED, self::REQUIRED, [], $data, $key, null), $missing))));
        }

        $body = new $class(...array_intersect_key($data, array_flip($keys)));
        $violations = $this->validator->validate($body);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException(previous: new ValidationFailedException($body, $violations));
        }

        return $body;
    }

    /**
     * @return array<int|string, mixed> the keys of the JSON object; none when the body is empty or no object
     */
    private static function decode(string $content): array
    {
        if ('' === $content) {
            return [];
        }
        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException();
        }
        if (!\is_array($data) || array_is_list($data)) {
            return [];
        }

        return $data;
    }
}
