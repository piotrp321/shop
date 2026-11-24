<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\VariantValues;
use App\Ordering\Domain\Order;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Promotion\Promotion;
use PHPUnit\Framework\TestCase;

/**
 * What Deptrac cannot tell apart inside a Domain layer: the generator takes the rules and value objects of the Domain
 * layers of Catalog, Pricing and Ordering, never an aggregate or a repository (spec P1a). Deptrac itself keeps it away
 * from every other layer (the Application, Infrastructure and UI layers of the contexts, and any other module).
 */
final class DemoDataBoundaryTest extends TestCase
{
    /**
     * @return list<string> the App classes the source of the module names, once
     */
    private static function namedClasses(): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src/DemoData'));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension()) {
                continue;
            }
            preg_match_all('/\\\\?\b(App(?:\\\\[A-Z][A-Za-z0-9]*)+)\b/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $class) {
                if (!str_starts_with($class, 'App\\DemoData\\')) {
                    $classes[$class] = true;
                }
            }
        }

        return array_keys($classes);
    }

    public function testTheModuleNamesOnlyDomainClassesOfTheContextsAndSharedInfrastructure(): void
    {
        $classes = self::namedClasses();

        self::assertNotSame([], $classes);
        foreach ($classes as $class) {
            self::assertMatchesRegularExpression(
                '/^App\\\\(?:(?:Catalog|Pricing|Ordering)\\\\Domain|Shared\\\\Infrastructure)\\\\/',
                $class,
                $class.' is not a Domain class of Catalog, Pricing or Ordering.',
            );
        }
    }

    public function testNoClassOfTheModuleUsesAnAggregateOrARepository(): void
    {
        foreach (self::namedClasses() as $class) {
            self::assertFalse(str_ends_with($class, 'Repository'), $class.' is a repository.');
            if (class_exists($class)) {
                self::assertFalse(self::isAggregate($class), $class.' is an aggregate.');
            }
        }
    }

    /**
     * The test tells the aggregates of the contexts from their value objects, which a repository also rebuilds from a
     * stored row with reconstitute() (spec api-input-contract, E7).
     */
    public function testTheAggregatesAreToldFromTheValueObjects(): void
    {
        foreach ([Product::class, Category::class, Order::class, VariantPrice::class, Promotion::class, DiscountCode::class] as $aggregate) {
            self::assertTrue(self::isAggregate($aggregate), $aggregate);
        }
        foreach ([VariantValues::class, ProductName::class, CategoryName::class] as $valueObject) {
            self::assertFalse(self::isAggregate($valueObject), $valueObject);
        }
    }

    /**
     * An aggregate is rebuilt from its stored state with reconstitute() and changes, so it is no readonly class; a
     * value object never changes.
     *
     * @param class-string $class
     */
    private static function isAggregate(string $class): bool
    {
        return method_exists($class, 'reconstitute') && !new \ReflectionClass($class)->isReadOnly();
    }
}
