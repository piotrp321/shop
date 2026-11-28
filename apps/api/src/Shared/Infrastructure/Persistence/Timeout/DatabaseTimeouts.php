<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Timeout;

/**
 * The time limits of the database for the process that runs now (spec: docs/features/runtime-config/spec.md,
 * "Limity czasu w bazie"). The one definition of the limits of each kind of process and of the routes that have the
 * longer one (config/services.yaml, `app.database_timeouts`).
 *
 * A process has a base profile (the console: none, a Messenger worker: its own, a migration: the lock timeout), and
 * an HTTP request has the profile of its route for as long as it lasts. TimeoutMiddleware sets what `current()` says
 * on the connection before its next statement, so a connection that serves one request after another (the worker
 * mode of FrankenPHP) never keeps the limit of the previous one.
 */
final class DatabaseTimeouts
{
    /** A command of the console (the generator, the reindexing, a backup, tests): no limit. */
    public const string CONSOLE = 'console';
    /** A request of the HTTP API. */
    public const string HTTP = 'http';
    /** A request of a route with the exception (`app.database_timeouts.long_routes`). */
    public const string HTTP_LONG = 'http_long';
    /** A Messenger worker. */
    public const string WORKER = 'worker';
    /** The migrations of the schema: a wait for a lock is bounded, a statement is not. */
    public const string MIGRATION = 'migration';

    private const array PROFILES = [self::CONSOLE, self::HTTP, self::HTTP_LONG, self::WORKER, self::MIGRATION];

    /** @var array<string, Timeouts> */
    private array $profiles = [];
    /** @var array<string, true> */
    private array $longRoutes;
    private string $base = self::CONSOLE;
    private ?string $request = null;

    /**
     * @param array<string, array{statement: int, idle_in_transaction: int, lock: int}> $profiles       by the name of the profile, in seconds
     * @param list<string>                                                              $longRoutes     the names of the routes of the longer limit
     * @param list<string>                                                              $testLongRoutes the same for the routes of the tests (empty outside them)
     */
    public function __construct(array $profiles, array $longRoutes, array $testLongRoutes = [])
    {
        foreach (self::PROFILES as $name) {
            if (!isset($profiles[$name])) {
                throw new \InvalidArgumentException(\sprintf('The profile "%s" of the database time limits is not configured.', $name));
            }
            $this->profiles[$name] = new Timeouts($profiles[$name]['statement'], $profiles[$name]['idle_in_transaction'], $profiles[$name]['lock']);
        }
        $this->longRoutes = array_fill_keys([...$longRoutes, ...$testLongRoutes], true);
    }

    public function current(): Timeouts
    {
        return $this->profiles[$this->request ?? $this->base];
    }

    /**
     * @param string|null $route the name of the route of the request, null when none matched
     */
    public function startRequest(?string $route): void
    {
        $this->request = null !== $route && isset($this->longRoutes[$route]) ? self::HTTP_LONG : self::HTTP;
    }

    public function endRequest(): void
    {
        $this->request = null;
    }

    public function useBase(string $profile): void
    {
        if (!\in_array($profile, [self::CONSOLE, self::WORKER, self::MIGRATION], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not the profile of a process.', $profile));
        }
        $this->base = $profile;
    }
}
