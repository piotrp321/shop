<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Infrastructure\Http\Cache\HttpStorefrontCache;
use App\Tests\Integration\Shared\Cache\HttpDouble;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\HttpClient;

/**
 * storefront:cache:purge-all from the kernel's console, with the storefront cache pointed at an HTTP double for
 * Varnish and the store. The test kernel waits no time between the attempts (app.storefront_cache.retry_delay).
 */
final class PurgeAllStorefrontCacheCommandTest extends KernelTestCase
{
    private const string PURGE_SECRET = 'purge-secret-in-test';
    private const string REVALIDATE_SECRET = 'revalidate-secret-in-test';

    private ?HttpDouble $double = null;

    protected function tearDown(): void
    {
        $this->double?->stop();
        parent::tearDown();
    }

    public function testPurgesTheCatalogInVarnishThenRevalidatesItInTheStore(): void
    {
        $double = $this->double = HttpDouble::start();

        $tester = $this->command($double->url, $double->url);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        $requests = $double->requests();
        self::assertSame(['varnish', 'store'], array_column($requests, 'side'));
        self::assertSame('catalog', $requests[0]['headers']['xkey-purge']);
        self::assertSame(['tags' => ['catalog']], json_decode($requests[1]['body'], true));
        self::assertStringContainsString('Varnish and the store pages were invalidated.', $this->display($tester));
    }

    public function testEachLayerIsRetriedUntilItSucceeds(): void
    {
        $double = $this->double = HttpDouble::start(['varnish' => ['statuses' => [503, 503, 200]], 'store' => ['statuses' => [503, 200]]]);

        $tester = $this->command($double->url, $double->url);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame(['varnish', 'varnish', 'varnish', 'store', 'store'], $double->sides());
        self::assertStringContainsString('Attempt 1 of 5 of the Varnish purge failed', $this->display($tester));
    }

    public function testAVarnishThatKeepsFailingEndsWithTwoAfterTheFifthAttemptAndNoRevalidation(): void
    {
        $double = $this->double = HttpDouble::start(['varnish' => ['statuses' => [503]]]);

        $tester = $this->command($double->url, $double->url);

        self::assertSame(2, $tester->execute([]), $tester->getDisplay());
        self::assertSame(array_fill(0, 5, 'varnish'), $double->sides());
        $display = $this->display($tester);
        self::assertStringContainsString('The storefront caches were not invalidated: The Varnish purge failed: HTTP 503.', $display);
        self::assertStringNotContainsString(self::PURGE_SECRET, $display);
    }

    public function testAnUnreachableVarnishEndsWithTwo(): void
    {
        $double = $this->double = HttpDouble::start();

        $tester = $this->command(HttpDouble::refusingUrl(), $double->url);

        self::assertSame(2, $tester->execute([]), $tester->getDisplay());
        self::assertSame([], $double->sides());
        self::assertStringContainsString('The Varnish purge failed', $this->display($tester));
    }

    public function testAStoreThatKeepsFailingEndsWithTwoAfterTheFifthAttempt(): void
    {
        $double = $this->double = HttpDouble::start(['store' => ['statuses' => [503]]]);

        $tester = $this->command($double->url, $double->url);

        self::assertSame(2, $tester->execute([]), $tester->getDisplay());
        self::assertSame(['varnish', 'store', 'store', 'store', 'store', 'store'], $double->sides());
        $display = $this->display($tester);
        self::assertStringContainsString('The storefront caches were not invalidated: The store revalidation failed: HTTP 503.', $display);
        self::assertStringNotContainsString(self::REVALIDATE_SECRET, $display);
    }

    private function command(string $varnishUrl, string $storeUrl): CommandTester
    {
        self::bootKernel();
        self::getContainer()->set(HttpStorefrontCache::class, new HttpStorefrontCache(HttpClient::create(), $varnishUrl, self::PURGE_SECRET, $storeUrl, self::REVALIDATE_SECRET, 2.0, 2.0));
        self::assertNotNull(self::$kernel);

        return new CommandTester(new Application(self::$kernel)->find('storefront:cache:purge-all'));
    }

    private function display(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }
}
