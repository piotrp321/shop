<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Sentry;

use PHPUnit\Framework\Attributes\After;
use Sentry\Integration\IntegrationRegistry;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turns the Sentry SDK on for the next kernel of a test, as a container does it: SENTRY_* environment variables (spec:
 * docs/features/sentry/spec.md, D2), with a DSN that points nowhere. What the SDK sends is kept by
 * RecordingSentryHttpClient, the HTTP client of the SDK in the tests, so nothing leaves the process. The SDK keeps its
 * hub in a static, so every test starts and ends with a new one.
 */
trait SentryEnvironment
{
    private const string SENTRY_DSN = 'https://public@sentry.example/1';
    private const string SENTRY_RELEASE = '0123456789abcdef0123456789abcdef01234567';
    private const string SENTRY_ENVIRONMENT = 'demo';

    /** @var list<string> */
    private array $sentryVariables = [];

    /**
     * @param array<string, string> $variables more SENTRY_* variables (e.g. SENTRY_TRACES_SAMPLE_RATE) or other values
     */
    private function enableSentry(array $variables = []): void
    {
        // A container built from a cold cache sets the SDK up while it warms (see resetSdk): build it first.
        self::bootKernel();
        self::ensureKernelShutdown();
        self::resetSdk();
        $variables += ['SENTRY_DSN' => self::SENTRY_DSN, 'SENTRY_RELEASE' => self::SENTRY_RELEASE, 'SENTRY_ENVIRONMENT' => self::SENTRY_ENVIRONMENT];
        foreach ($variables as $name => $value) {
            $this->setVariable($name, $value);
        }
    }

    private function setVariable(string $name, string $value): void
    {
        $_SERVER[$name] = $_ENV[$name] = $value;
        $this->sentryVariables[] = $name;
    }

    #[After]
    protected function disableSentry(): void
    {
        foreach ($this->sentryVariables as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        $this->sentryVariables = [];
        self::resetSdk();
    }

    /**
     * The SDK keeps its hub, its integrations and their event processors in statics, set up once per process: in
     * production one container lives as long as the process, in the tests every kernel has its own. The processors of
     * the integrations of an earlier kernel would read its services (the request of RequestIntegration), so the next
     * client sets them up again.
     */
    private static function resetSdk(): void
    {
        SentrySdk::init();
        (new \ReflectionProperty(IntegrationRegistry::class, 'instance'))->setValue(null, null);
        (new \ReflectionProperty(Scope::class, 'globalEventProcessors'))->setValue(null, []);
    }

    private static function sentry(ContainerInterface $container): RecordingSentryHttpClient
    {
        $recorder = $container->get(RecordingSentryHttpClient::class);
        \assert($recorder instanceof RecordingSentryHttpClient);

        return $recorder;
    }
}
