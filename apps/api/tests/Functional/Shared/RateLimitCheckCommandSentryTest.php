<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\Shared\Sentry\SentryEnvironment;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A rate-limit:check that fails is an exit code, not an error of the console: with Sentry on and a Redis that refuses
 * connections it exits with a code other than 0, says why, and sends no event (spec: docs/features/abuse-limits/spec.md,
 * L12, D16), so the failing check of every five minutes is not a stream of errors in Sentry.
 */
final class RateLimitCheckCommandSentryTest extends KernelTestCase
{
    use SentryEnvironment;

    private ?string $redisHost = null;

    protected function tearDown(): void
    {
        if (null !== $this->redisHost) {
            unset($_SERVER['REDIS_HOST'], $_ENV['REDIS_HOST']);
            putenv('REDIS_HOST='.$this->redisHost);
        }
        parent::tearDown();
    }

    public function testAFailingCheckSendsNoEventToSentry(): void
    {
        $this->enableSentry();
        $current = $_SERVER['REDIS_HOST'] ?? $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST');
        $this->redisHost = \is_string($current) ? $current : '';
        $_SERVER['REDIS_HOST'] = $_ENV['REDIS_HOST'] = '127.0.0.1'; // nothing listens on 6379 there: the connection is refused
        putenv('REDIS_HOST=127.0.0.1');
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput(['command' => 'rate-limit:check']), $output);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('The Redis of the rate limiters failed', $output->fetch());
        self::assertCount(0, self::sentry(self::getContainer())->items('event'), 'a failed check is not an error of the console');
    }
}
