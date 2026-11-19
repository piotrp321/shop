<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Tests\Integration\Shared\RateLimit\ConfigurationOfEnvironment;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\TestCase;
use Sentry\Monolog\LogToSentryIssueHandler;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The Monolog handlers of the `prod` container as built from config/ (spec: docs/features/abuse-limits/spec.md, L10 and
 * D13): the `security` channel, where a token that cannot be verified is logged as an error, has neither the Sentry
 * handler nor the `fingers_crossed` buffer, only its own handler of JSON lines from the level warning.
 */
final class MonologSecurityChannelConfigurationTest extends TestCase
{
    /** @var array<string, Definition> */
    private static array $definitions = [];

    public function testTheSecurityChannelHasOnlyAJsonHandlerFromWarningOutsideTheSentryHandlerAndTheBuffer(): void
    {
        $handlers = self::handlersOf('monolog.logger.security');

        self::assertCount(1, $handlers, 'one handler');
        $handler = self::$definitions[$handlers[0]];
        self::assertSame(StreamHandler::class, $handler->getClass());
        self::assertSame('php://stderr', $handler->getArgument(0));
        self::assertSame('warning', $handler->getArgument(1));
        self::assertSame(['monolog.formatter.json'], self::argumentsOfCalls($handler, 'setFormatter'), 'JSON lines');
    }

    public function testTheOtherChannelsKeepTheSentryHandlerAndTheBuffer(): void
    {
        $handlers = self::handlersOf('monolog.logger');

        $classes = array_map(static fn (string $id): ?string => self::$definitions[$id]->getClass(), $handlers);
        self::assertContains(LogToSentryIssueHandler::class, $classes);
        self::assertContains(FingersCrossedHandler::class, $classes);
    }

    public function testTheTokenHandlersOfBothFirewallsLogToTheSecurityChannel(): void
    {
        self::definitions();

        // The handlers of the firewalls are decorated (KeySourceOutageTokenHandler), so they are the inner services.
        foreach (['app.security.shop_token_handler.inner', 'app.security.admin_token_handler.inner'] as $id) {
            self::assertArrayHasKey($id, self::$definitions);
            // A child of the abstract oidc handler: its sixth argument is the logger.
            $definition = self::$definitions[$id];
            $logger = $definition->getArgument(5);
            self::assertInstanceOf(Reference::class, $logger, $id);
            self::assertSame('monolog.logger.security', (string) $logger, $id);
        }
    }

    /**
     * @return list<string> the ids of the handlers pushed to the logger
     */
    private static function handlersOf(string $logger): array
    {
        self::definitions();
        self::assertArrayHasKey($logger, self::$definitions);

        return self::argumentsOfCalls(self::$definitions[$logger], 'pushHandler');
    }

    /**
     * @return list<string> the first argument (the id of a reference) of every call of the method
     */
    private static function argumentsOfCalls(Definition $definition, string $method): array
    {
        $arguments = [];
        foreach ($definition->getMethodCalls() as $call) {
            self::assertIsArray($call);
            if ($method === ($call[0] ?? null)) {
                self::assertIsArray($call[1] ?? null);
                $first = $call[1][0] ?? null;
                self::assertTrue($first instanceof Reference || \is_string($first));
                $arguments[] = (string) $first;
            }
        }

        return $arguments;
    }

    private static function definitions(): void
    {
        if ([] === self::$definitions) {
            self::$definitions = ConfigurationOfEnvironment::definitions('prod');
        }
    }
}
