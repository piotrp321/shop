<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes that exist only in the test environment (see config/routes.yaml): a query that sleeps for `seconds` and
 * the time limit of the statement the connection had while it slept. `test_database_sleep_long` is one of the
 * routes with the longer limit (`app.database_timeouts.test_long_routes`).
 */
#[AsController]
final readonly class TestDatabaseController
{
    public function __construct(private Connection $connection)
    {
    }

    #[Route('/_test/database/sleep', name: 'test_database_sleep', methods: ['GET'])]
    public function sleep(Request $request): JsonResponse
    {
        return $this->sleepFor((float) $request->query->get('seconds', '0'));
    }

    #[Route('/_test/database/sleep-long', name: 'test_database_sleep_long', methods: ['GET'])]
    public function sleepLong(Request $request): JsonResponse
    {
        return $this->sleepFor((float) $request->query->get('seconds', '0'));
    }

    private function sleepFor(float $seconds): JsonResponse
    {
        $this->connection->executeQuery('SELECT pg_sleep(?)', [$seconds]);

        $milliseconds = $this->connection->fetchOne("SELECT setting FROM pg_settings WHERE name = 'statement_timeout'");
        \assert(\is_string($milliseconds));

        return new JsonResponse(['statement_timeout_ms' => (int) $milliseconds]);
    }
}
