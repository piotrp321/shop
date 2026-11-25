<?php

declare(strict_types=1);

namespace App\Tests\Integration\Delivery;

use App\Delivery\Application\Demo\DemoDeliveryMethodSeeder;
use App\Delivery\Application\Port\DeliveryReset;
use App\Delivery\Infrastructure\Demo\YamlDemoDeliveryMethodSource;
use App\Delivery\UI\Console\SeedDemoDeliveryMethodsCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * delivery:seed-demo on shop_test (rolled back after the test): the methods of fixtures/delivery-methods.yaml replace
 * every method; prod refuses without --force-reset, and an invalid file changes nothing.
 */
final class SeedDemoDeliveryMethodsTest extends KernelTestCase
{
    private Connection $connection;
    private ?string $file = null;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM delivery_method');
        $this->connection->insert('delivery_method', [
            'id' => Uuid::v7()->toRfc4122(), 'name' => 'Stara metoda', 'description' => '', 'price_gross' => 100, 'free_from_gross' => null,
            'active' => true, 'position' => 1, 'tracking_url_template' => null, 'created_at' => '2026-11-01 10:00:00+00', 'updated_at' => '2026-11-01 10:00:00+00', 'version' => 3,
        ], ['active' => ParameterType::BOOLEAN]);
    }

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function testReplacesEveryMethodWithTheTwoCouriersOfTheFile(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('delivery:seed-demo'));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('delivery methods: 2', $tester->getDisplay());
        self::assertSame($this->seeded(), $this->rows());
    }

    public function testASecondRunGivesTheSameMethods(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('delivery:seed-demo'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame($this->seeded(), $this->rows());
    }

    public function testProdWithoutForceResetRefusesAndChangesNothing(): void
    {
        $tester = $this->tester('prod');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('--force-reset', $tester->getDisplay());
        self::assertSame([['Stara metoda', '', 100, null, true, 1, null, 3]], $this->rows());
    }

    public function testProdWithForceResetReplacesTheMethods(): void
    {
        $tester = $this->tester('prod');

        self::assertSame(Command::SUCCESS, $tester->execute(['--force-reset' => true]), $tester->getDisplay());
        self::assertSame($this->seeded(), $this->rows());
    }

    public function testAnInvalidFileFailsBeforeAnyChange(): void
    {
        $tester = $this->tester('test', "- name: Kurier\n  priceGross: 1999\n  position: 10\n  trackingUrlTemplate: \"http://example.com/{numer}\"\n");

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
        self::assertSame([['Stara metoda', '', 100, null, true, 1, null, 3]], $this->rows());
    }

    public function testAMissingFileFailsBeforeAnyChange(): void
    {
        $tester = $this->tester('test', null);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('does not exist', $tester->getDisplay());
        self::assertCount(1, $this->rows());
    }

    /**
     * @param string|false|null $yaml the file to read: false for the real one, null for a missing one
     */
    private function tester(string $environment, string|false|null $yaml = false): CommandTester
    {
        $path = \dirname(__DIR__, 3).'/fixtures/delivery-methods.yaml';
        if (null === $yaml) {
            $path = sys_get_temp_dir().'/delivery-methods-missing-'.bin2hex(random_bytes(4)).'.yaml';
        } elseif (\is_string($yaml)) {
            $path = $this->file = (string) tempnam(sys_get_temp_dir(), 'delivery-methods');
            file_put_contents($path, $yaml);
        }
        $seeder = self::getContainer()->get(DemoDeliveryMethodSeeder::class);
        self::assertInstanceOf(DemoDeliveryMethodSeeder::class, $seeder);
        $reset = self::getContainer()->get(DeliveryReset::class);
        self::assertInstanceOf(DeliveryReset::class, $reset);
        $application = new \Symfony\Component\Console\Application();
        $application->addCommand(new SeedDemoDeliveryMethodsCommand(new YamlDemoDeliveryMethodSource($path), $seeder, $reset, $environment));

        return new CommandTester($application->find('delivery:seed-demo'));
    }

    /**
     * @return list<list<mixed>> the methods of the file as stored by the seed
     */
    private function seeded(): array
    {
        return [
            ['Kurier ekspresowy', 'Dostawa następnego dnia roboczego', 3999, 99_900, true, 20, null, 1],
            ['Kurier standardowy', 'Dostawa w 2–3 dni robocze', 1999, 29_900, true, 10, 'https://tracking.example.com/{numer}', 1],
        ];
    }

    /**
     * @return list<list<mixed>>
     */
    private function rows(): array
    {
        return array_map(array_values(...), $this->connection->fetchAllAssociative('SELECT name, description, price_gross, free_from_gross, active, position, tracking_url_template, version FROM delivery_method ORDER BY name'));
    }
}
