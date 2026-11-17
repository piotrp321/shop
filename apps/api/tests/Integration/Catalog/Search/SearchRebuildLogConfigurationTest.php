<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductSearchIndexRebuilder;
use App\Catalog\Infrastructure\Search\ElasticsearchSearchIndexMaintenance;
use App\Tests\Integration\Shared\RateLimit\ConfigurationOfEnvironment;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The log of the search index rebuild (progress and retries of the bulk writes, spec: docs/features/search/spec.md) as
 * the `prod` container is built from config/: its own `search_rebuild` channel, written to stderr as JSON from `info`
 * up outside the `fingers_crossed` buffer (which keeps the logs of failed requests only, so a console command that
 * ends without an error would never write them), and used by both services that log it.
 */
final class SearchRebuildLogConfigurationTest extends TestCase
{
    private const string CHANNEL = 'monolog.logger.search_rebuild';

    /** @var array<string, Definition>|null */
    private static ?array $definitions = null;

    public function testInProductionTheRebuildLogGoesToStderrFromInfoOutsideTheBuffer(): void
    {
        $definitions = self::definitions();
        self::assertArrayHasKey(self::CHANNEL, $definitions);
        $handlers = self::handlersOf($definitions[self::CHANNEL]);

        $streams = array_values(array_filter($handlers, static fn (string $id): bool => StreamHandler::class === $definitions[$id]->getClass()));
        self::assertCount(1, $streams, 'one stream handler for the channel');
        $stream = $definitions[$streams[0]];
        self::assertSame('php://stderr', $stream->getArgument(0));
        self::assertSame('info', $stream->getArgument(1), 'from the level info up');
        self::assertContains(['setFormatter', 'monolog.formatter.json'], self::calls($stream), 'JSON lines');

        foreach ($handlers as $id) {
            self::assertNotSame(FingersCrossedHandler::class, $definitions[$id]->getClass(), 'the buffer does not take the channel');
        }
        self::assertContains(FingersCrossedHandler::class, array_map(static fn (string $id): ?string => $definitions[$id]->getClass(), self::handlersOf($definitions['monolog.logger'])), 'the other channels keep the buffer');
        self::assertNotContains($streams[0], self::handlersOf($definitions['monolog.logger']), 'the other channels do not use the handler');
    }

    public function testBothServicesThatLogTheRebuildGetTheLoggerOfTheChannel(): void
    {
        $definitions = self::definitions();

        foreach ([ProductSearchIndexRebuilder::class, ElasticsearchSearchIndexMaintenance::class] as $class) {
            $loggers = array_filter(
                $definitions[$class]->getArguments(),
                static fn (mixed $argument): bool => $argument instanceof Reference && self::CHANNEL === (string) $argument,
            );
            self::assertCount(1, $loggers, $class.' logs to the search_rebuild channel');
        }
    }

    /**
     * @return list<string> the ids of the handlers pushed to the logger
     */
    private static function handlersOf(Definition $logger): array
    {
        $handlers = [];
        foreach (self::calls($logger) as [$method, $reference]) {
            if ('pushHandler' === $method) {
                $handlers[] = $reference;
            }
        }

        return $handlers;
    }

    /**
     * @return list<array{string, string}> the method calls of the definition: the method and its first argument as a string
     *                                     (the id of a reference)
     */
    private static function calls(Definition $definition): array
    {
        $calls = [];
        foreach ($definition->getMethodCalls() as $call) {
            self::assertIsArray($call);
            [$method, $arguments] = $call + [1 => []];
            self::assertIsString($method);
            self::assertIsArray($arguments);
            $first = $arguments[0] ?? '';
            $calls[] = [$method, $first instanceof Reference ? (string) $first : (\is_scalar($first) ? (string) $first : get_debug_type($first))];
        }

        return $calls;
    }

    /**
     * @return array<string, Definition>
     */
    private static function definitions(): array
    {
        return self::$definitions ??= ConfigurationOfEnvironment::definitions('prod');
    }
}
