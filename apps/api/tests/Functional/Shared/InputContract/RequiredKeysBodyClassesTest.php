<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

use App\Shared\UI\Http\RequiredKeysBody;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * RequiredKeysBodyResolver hands the request class the values as JSON gives them, never cast, so a typed constructor
 * parameter would turn a value of another type into a TypeError (a 500) instead of a 422 of its constraints. Every
 * request class that a controller of the router takes with #[RequiredKeysBody] has parameters without a type or
 * `mixed` only (spec: docs/features/api-input-contract/spec.md, "Rozwiązanie", point 5; #441).
 */
final class RequiredKeysBodyClassesTest extends KernelTestCase
{
    public function testEveryRequestClassOfTheRouterTakesItsKeysUntyped(): void
    {
        $classes = $this->requestClasses();

        self::assertGreaterThanOrEqual(13, \count($classes));
        self::assertSame([], self::typedParameters($classes));
    }

    public function testARequestClassWithATypedParameterBreaksTheRule(): void
    {
        self::assertSame(
            [IntKeyRequest::class.'::$quantity is int'],
            self::typedParameters([IntKeyRequest::class]),
        );
    }

    /**
     * @param list<class-string> $classes
     *
     * @return list<string> each parameter with a type other than mixed
     */
    private static function typedParameters(array $classes): array
    {
        $typed = [];
        foreach ($classes as $class) {
            foreach (new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if (null !== $type && 'mixed' !== (string) $type) {
                    $typed[] = \sprintf('%s::$%s is %s', $class, $parameter->getName(), $type);
                }
            }
        }

        return $typed;
    }

    /**
     * The classes of the arguments marked #[RequiredKeysBody] of every controller of the router.
     *
     * @return list<class-string>
     */
    private function requestClasses(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $classes = [];
        foreach ($router->getRouteCollection()->all() as $route) {
            $controller = $route->getDefault('_controller');
            if (!\is_string($controller) || !str_starts_with($controller, 'App\\')) {
                continue;
            }
            [$class, $method] = explode('::', $controller, 2) + [1 => '__invoke'];
            if (!class_exists($class)) {
                continue;
            }
            foreach (new \ReflectionMethod($class, $method)->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ([] !== $parameter->getAttributes(RequiredKeysBody::class) && $type instanceof \ReflectionNamedType && class_exists($type->getName())) {
                    $classes[$type->getName()] = true;
                }
            }
        }

        return array_keys($classes);
    }
}
