<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Application;

use App\Delivery\Application\Command\CreateDeliveryMethod;
use App\Delivery\Application\Command\CreateDeliveryMethodHandler;
use App\Delivery\Application\Command\ReviseDeliveryMethod;
use App\Delivery\Application\Command\ReviseDeliveryMethodHandler;
use App\Delivery\Application\Port\Clock;
use App\Delivery\Application\Query\DeliveryMethodView;
use App\Delivery\Application\Query\GetDeliveryMethod;
use App\Delivery\Application\Query\GetDeliveryMethodHandler;
use App\Delivery\Application\Query\ListDeliveryMethods;
use App\Delivery\Application\Query\ListDeliveryMethodsHandler;
use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodLimitReached;
use App\Delivery\Domain\DeliveryMethodModifiedConcurrently;
use App\Delivery\Domain\DeliveryMethodNotFound;
use App\Delivery\Domain\InvalidDeliveryMethod;
use App\Tests\Unit\Delivery\DeliveryMethods;
use App\Tests\Unit\Delivery\InMemoryDeliveryMethodRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The commands and reads of the panel (docs/features/delivery/spec.md, W1): the limit of 20 active methods counted from
 * the stored ones, the version a change is based on, an unknown method, and the order of the list.
 */
final class DeliveryMethodCommandsTest extends TestCase
{
    private const string ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c8a01';
    private const string NOW = '2026-11-20T12:00:00+00:00';

    private InMemoryDeliveryMethodRepository $methods;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->methods = new InMemoryDeliveryMethodRepository();
        $this->clock = new class implements Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(DeliveryMethodCommandsTest::nowText());
            }
        };
    }

    public static function nowText(): string
    {
        return self::NOW;
    }

    public function testACreatedMethodIsStoredWithItsValuesTrimmed(): void
    {
        $this->create(name: " Kurier\u{A0}", description: null, freeFrom: null, template: "\u{3000}");

        $method = $this->read(self::ID);
        self::assertEquals(new DeliveryMethodView(self::ID, 'Kurier', '', 1999, null, true, 10, null, 1), $method);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->methods->find(DeliveryMethodId::fromString(self::ID))?->createdAt());
    }

    public function testATwentyFirstActiveMethodIsRefusedAndNothingIsStored(): void
    {
        $this->storeActive(20);

        try {
            $this->create();
            self::fail('The 21st active method must be refused.');
        } catch (DeliveryMethodLimitReached) {
            self::assertNull($this->methods->find(DeliveryMethodId::fromString(self::ID)));
        }
    }

    public function testAnInactiveMethodIsCreatedWithTwentyActiveOnes(): void
    {
        $this->storeActive(20);

        $this->create(active: false);

        self::assertFalse($this->read(self::ID)->active);
    }

    public function testInactiveMethodsDoNotCountToTheLimit(): void
    {
        $this->storeActive(19);
        $this->methods->add(DeliveryMethods::stored(name: 'Wyłączona', active: false));

        $this->create();

        self::assertSame(20, $this->methods->countActive());
    }

    public function testAnInvalidValueOfACreateIsRefusedAtItsField(): void
    {
        $this->expectExceptionObject(InvalidDeliveryMethod::priceRange());

        $this->create(price: -1);
    }

    public function testAReviseChangesTheMethodAtTheVersionItIsBasedOn(): void
    {
        $this->methods->add(DeliveryMethods::stored(id: self::ID));

        $this->revise(version: 1, name: 'Kurier ekspresowy', price: 3999, active: false);

        self::assertEquals(new DeliveryMethodView(self::ID, 'Kurier ekspresowy', '', 3999, null, false, 10, null, 2), $this->read(self::ID));
    }

    public function testAReviseOfAnotherVersionIsAConflictAndChangesNothing(): void
    {
        $this->methods->add(DeliveryMethods::stored(id: self::ID, version: 2));

        try {
            $this->revise(version: 1, name: 'Inna');
            self::fail('The stale change must be refused.');
        } catch (DeliveryMethodModifiedConcurrently $conflict) {
            self::assertSame('Metoda dostawy została w międzyczasie zmieniona. Odśwież ją i spróbuj ponownie.', $conflict->getMessage());
            self::assertSame('Kurier standardowy', $this->read(self::ID)->name);
        }
    }

    public function testActivatingATwentyFirstMethodIsRefused(): void
    {
        $this->storeActive(20);
        $this->methods->add(DeliveryMethods::stored(name: 'Wyłączona', active: false, id: self::ID));

        $this->expectException(DeliveryMethodLimitReached::class);

        $this->revise(version: 1, name: 'Wyłączona', active: true);
    }

    public function testChangingAnInactiveMethodWithoutActivatingItPassesWithTwentyActiveOnes(): void
    {
        $this->storeActive(20);
        $this->methods->add(DeliveryMethods::stored(name: 'Wyłączona', active: false, id: self::ID));

        $this->revise(version: 1, name: 'Wyłączona', price: 999, active: false);

        self::assertSame(999, $this->read(self::ID)->priceGross);
    }

    public function testChangingAnActiveMethodPassesWithTwentyActiveOnes(): void
    {
        $this->storeActive(19);
        $this->methods->add(DeliveryMethods::stored(name: 'Aktywna', id: self::ID));

        $this->revise(version: 1, name: 'Aktywna', price: 999, active: true);

        self::assertSame(999, $this->read(self::ID)->priceGross);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'an unknown id' => ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c8fff'];
        yield 'no UUID' => ['kurier-1'];
    }

    #[DataProvider('unknownIds')]
    public function testAnUnknownMethodIsNotFound(string $id): void
    {
        try {
            $this->read($id);
            self::fail('The method must not be found.');
        } catch (DeliveryMethodNotFound $notFound) {
            self::assertSame('Metoda dostawy nie istnieje.', $notFound->getMessage());
        }

        $this->expectException(DeliveryMethodNotFound::class);
        new ReviseDeliveryMethodHandler($this->methods, $this->clock)(new ReviseDeliveryMethod($id, 1, 'Kurier', null, 1999, null, true, 10, null));
    }

    public function testTheListHasTheActiveMethodsByPositionAndNameThenTheInactiveOnesByName(): void
    {
        $this->methods->add(
            DeliveryMethods::stored(name: 'Zeta', active: false, position: 0),
            DeliveryMethods::stored(name: 'Kurier B', position: 20),
            DeliveryMethods::stored(name: 'Alfa', active: false, position: 999),
            DeliveryMethods::stored(name: 'Kurier A', position: 20),
            DeliveryMethods::stored(name: 'Paczka', position: 5),
        );

        $list = new ListDeliveryMethodsHandler($this->methods)(new ListDeliveryMethods());

        self::assertSame(
            ['Paczka', 'Kurier A', 'Kurier B', 'Alfa', 'Zeta'],
            array_map(static fn (DeliveryMethodView $method): string => $method->name, $list),
        );
    }

    private function storeActive(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->methods->add(DeliveryMethods::stored(name: 'Aktywna '.$i));
        }
    }

    private function create(string $name = 'Kurier', ?string $description = 'Dostawa w 2 dni', int $price = 1999, ?int $freeFrom = 29_900, bool $active = true, ?string $template = 'https://example.com/{numer}'): void
    {
        new CreateDeliveryMethodHandler($this->methods, $this->clock)(new CreateDeliveryMethod(self::ID, $name, $description, $price, $freeFrom, $active, 10, $template));
    }

    private function revise(int $version, string $name, int $price = 1999, bool $active = true): void
    {
        new ReviseDeliveryMethodHandler($this->methods, $this->clock)(new ReviseDeliveryMethod(self::ID, $version, $name, null, $price, null, $active, 10, null));
    }

    private function read(string $id): DeliveryMethodView
    {
        return new GetDeliveryMethodHandler($this->methods)(new GetDeliveryMethod($id));
    }
}
