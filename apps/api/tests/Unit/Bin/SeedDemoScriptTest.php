<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bin;

use PHPUnit\Framework\TestCase;

/**
 * Runs bin/seed-demo from a temporary copy next to a fake bin/console that logs its arguments, so no real seed runs.
 * The script finds the console relative to itself, which is what lets the copy pick up the fake.
 */
final class SeedDemoScriptTest extends TestCase
{
    private const SEEDS = [
        'catalog:seed-demo',
        'pricing:seed-demo',
        'payment-simulator:seed-demo',
        'payment:seed-demo',
        'ordering:seed-demo',
        'inventory:seed-demo',
    ];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/seed-demo-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/bin', 0o700, true);
        copy(\dirname(__DIR__, 3).'/bin/seed-demo', $this->dir.'/bin/seed-demo');
        chmod($this->dir.'/bin/seed-demo', 0o700);

        // Logs the arguments of every call; fails the call named in FAKE_FAIL_ON (the command name is the first argument).
        file_put_contents($this->dir.'/bin/console', <<<'SH'
            #!/bin/sh
            echo "$*" >> "$(dirname "$0")/calls.log"
            if [ "${FAKE_FAIL_ON:-}" = "$1" ]; then
                echo "boom in $1" >&2
                exit 3
            fi
            SH);
        chmod($this->dir.'/bin/console', 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/bin/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir.'/bin');
        rmdir($this->dir);
    }

    public function testRunsTheSeedsThenTheReindexAndThePurgeInTheOrderOfMakeSeed(): void
    {
        [$code] = $this->runScript(['APP_ENV' => 'dev']);

        self::assertSame(0, $code);
        self::assertSame([...self::SEEDS, 'catalog:reindex', 'storefront:cache:purge-all'], $this->calls());
    }

    public function testPassesForceResetToTheSeedsOnlyInProd(): void
    {
        [$code] = $this->runScript(['APP_ENV' => 'prod']);

        self::assertSame(0, $code);
        self::assertSame(
            [
                ...array_map(static fn (string $seed): string => $seed.' --force-reset', self::SEEDS),
                'catalog:reindex',
                'storefront:cache:purge-all',
            ],
            $this->calls(),
        );
    }

    public function testDoesNotPassForceResetOutsideProd(): void
    {
        foreach ([['APP_ENV' => 'test'], []] as $env) {
            file_exists($this->dir.'/bin/calls.log') && unlink($this->dir.'/bin/calls.log');

            [$code] = $this->runScript($env);

            self::assertSame(0, $code);
            foreach ($this->calls() as $call) {
                self::assertStringNotContainsString('--force-reset', $call);
            }
        }
    }

    public function testAFailingSeedStopsTheScriptWithANonZeroCodeAndRunsNothingAfterIt(): void
    {
        [$code, $stderr] = $this->runScript(['APP_ENV' => 'prod', 'FAKE_FAIL_ON' => 'payment:seed-demo']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('boom in payment:seed-demo', $stderr);
        self::assertSame(
            ['catalog:seed-demo --force-reset', 'pricing:seed-demo --force-reset', 'payment-simulator:seed-demo --force-reset', 'payment:seed-demo --force-reset'],
            $this->calls(),
        );
    }

    public function testAFailingReindexStopsTheScriptBeforeThePurge(): void
    {
        [$code] = $this->runScript(['APP_ENV' => 'dev', 'FAKE_FAIL_ON' => 'catalog:reindex']);

        self::assertNotSame(0, $code);
        self::assertSame([...self::SEEDS, 'catalog:reindex'], $this->calls());
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{int, string} exit code and standard error
     */
    private function runScript(array $env): array
    {
        $process = proc_open(
            ['/bin/sh', $this->dir.'/bin/seed-demo'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->dir,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin', ...$env],
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stderr];
    }

    /**
     * @return list<string>
     */
    private function calls(): array
    {
        $log = file_get_contents($this->dir.'/bin/calls.log');

        return false === $log ? [] : explode("\n", trim($log));
    }
}
