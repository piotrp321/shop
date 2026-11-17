<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\Shared\Sentry\SentryEnvironment;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A wrong SENTRY_TRACES_SAMPLE_RATE does not stop the API or the worker: the SDK starts without traces (issue #559), and
 * a correct value reaches the SDK.
 */
final class SentrySampleRateTest extends KernelTestCase
{
    use SentryEnvironment;

    public function testAWrongRateStartsTheSdkWithoutTraces(): void
    {
        $this->enableSentry(['SENTRY_TRACES_SAMPLE_RATE' => '0,1']);

        self::assertSame(0.0, $this->tracesSampleRate());
    }

    public function testACorrectRateReachesTheSdk(): void
    {
        $this->enableSentry(['SENTRY_TRACES_SAMPLE_RATE' => '0.1']);

        self::assertSame(0.1, $this->tracesSampleRate());
    }

    private function tracesSampleRate(): ?float
    {
        self::bootKernel();
        $hub = self::getContainer()->get(HubInterface::class);
        \assert($hub instanceof HubInterface);
        $client = $hub->getClient();
        self::assertNotNull($client);

        return $client->getOptions()->getTracesSampleRate();
    }
}
