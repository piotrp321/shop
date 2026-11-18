<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Sentry;

use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * A console command that stops on an error hands it on to the console as an uncaught exception, so the console
 * listener of Sentry reports it (spec: docs/features/small-fixes/spec.md, "Komendy konsoli"): exactly one event with
 * the original exception as its cause, the exit code of the command's documentation and the message for the operator
 * in the output. The error is a trigger of the database that fails the write the command makes (rolled back with the
 * test).
 */
final class ConsoleCommandFailureTest extends KernelTestCase
{
    use SentryEnvironment;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->enableSentry();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testPurgingExpiredCartsReportsTheFailureAndExitsWithTwo(): void
    {
        $this->connection->executeStatement('DELETE FROM cart_cart');
        CartFixture::cart($this->connection, expiresAt: '-1 day');
        $this->failWrites('cart_cart', 'DELETE', 'forced failure of a purge');

        $tester = $this->runConsole('cart:purge-expired', []);

        $this->assertReported('forced failure of a purge');
        self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('The purge stopped: ', $this->flat($tester));
        self::assertStringContainsString('forced failure of a purge', $this->flat($tester));
    }

    public function testRebuildingTheReadModelsReportsTheFailureAndExitsWithOne(): void
    {
        $this->connection->executeStatement('DELETE FROM ordering_sales_hour');
        $this->connection->executeStatement('DELETE FROM ordering_customer');
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 12:10:00+00:00');
        $this->failWrites('ordering_sales_hour', 'INSERT', 'forced failure of the sales');

        $tester = $this->runConsole('ordering:read-models:rebuild', []);

        $this->assertReported('forced failure of the sales');
        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('The rebuild stopped: ', $this->flat($tester));
        self::assertStringContainsString('forced failure of the sales', $this->flat($tester));
    }

    public function testReconcilingTheTaxReportsTheFailureAndExitsWithTwo(): void
    {
        $this->connection->executeStatement('DELETE FROM pricing_price_history');
        $this->connection->executeStatement('DELETE FROM pricing_variant_price');
        // A price with a rate that is not the current one of its class: --apply saves it.
        $this->connection->executeStatement(
            "INSERT INTO pricing_variant_price (variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at) VALUES ('0192f0c4-7c1a-7c4e-9a6e-0000000000f1', 'PLN', 1000, 926, 74, 800, 'standard', 1, now())",
        );
        $this->failWrites('pricing_variant_price', 'UPDATE', 'forced failure of a reprice');

        $tester = $this->runConsole('pricing:reconcile-tax', ['--apply' => true]);

        $this->assertReported('forced failure of a reprice');
        self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('The reconciliation stopped: ', $this->flat($tester));
        self::assertStringContainsString('forced failure of a reprice', $this->flat($tester));
    }

    public function testTheBackfillReportsTheFailureOfAStepAndExitsWithOne(): void
    {
        $this->connection->executeStatement('DELETE FROM ordering_sales_hour');
        $this->connection->executeStatement('DELETE FROM ordering_customer');
        OrderingFixture::order($this->connection, paid: true, paidAt: '2026-11-14 12:10:00+00:00');
        $this->failWrites('ordering_sales_hour', 'INSERT', 'forced failure of the sales');

        $tester = $this->runConsole('app:backfill', ['--force' => true]);

        $this->assertReported('forced failure of the sales');
        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('ordering:read-models:rebuild:sales: failed: ', $this->flat($tester));
        self::assertStringContainsString('The steps after it did not run.', $this->flat($tester));
    }

    private function failWrites(string $table, string $operation, string $message): void
    {
        $function = 'pg_temp.fail_'.$table;
        $this->connection->executeStatement(\sprintf("CREATE FUNCTION %s() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION '%s'; END \$\$", $function, $message));
        $this->connection->executeStatement(\sprintf('CREATE TRIGGER fail_%s BEFORE %s ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $table, $operation, $table, $function));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runConsole(string $command, array $input): ApplicationTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => $command] + $input);

        return $tester;
    }

    /**
     * One event, with the error of the database as the cause of the exception the command handed on.
     */
    private function assertReported(string $databaseMessage): void
    {
        $events = self::sentry(self::getContainer())->items('event');
        self::assertCount(1, $events);
        self::assertIsArray($events[0]['exception'] ?? null);
        self::assertIsArray($events[0]['exception']['values'] ?? null);
        $values = $events[0]['exception']['values'];
        self::assertGreaterThanOrEqual(2, \count($values));
        $messages = implode("\n", array_map(static fn (mixed $value): string => \is_array($value) && \is_string($value['value'] ?? null) ? $value['value'] : '', $values));
        self::assertStringContainsString($databaseMessage, $messages);
    }

    private function flat(ApplicationTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }
}
