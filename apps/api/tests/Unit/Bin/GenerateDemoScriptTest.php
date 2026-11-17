<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bin;

use PHPUnit\Framework\TestCase;

/**
 * Runs bin/generate-demo from a temporary copy next to a fake bin/console and a fake bin/seed-demo that log their
 * arguments, so no real step runs. The script finds both relative to itself, which lets the copy pick up the fakes.
 * The fake console fails a call whose arguments contain FAKE_FAIL_ON and, for a call that contains FAKE_HANG_ON, records
 * its process id and waits (a part that has to be stopped by the script).
 */
final class GenerateDemoScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/generate-demo-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/bin', 0o700, true);
        copy(\dirname(__DIR__, 3).'/bin/generate-demo', $this->dir.'/bin/generate-demo');
        chmod($this->dir.'/bin/generate-demo', 0o700);

        file_put_contents($this->dir.'/bin/console', <<<'SH'
            #!/bin/sh
            echo "$*" >> "$(dirname "$0")/calls.log"
            if [ -n "${FAKE_HANG_ON:-}" ] && case "$*" in *"$FAKE_HANG_ON"*) true;; *) false;; esac; then
                echo "$$" >> "$(dirname "$0")/hung.log"
                exec sleep 60
            fi
            if [ -n "${FAKE_FAIL_ON:-}" ] && case "$*" in *"$FAKE_FAIL_ON"*) true;; *) false;; esac; then
                echo "boom in $*" >&2
                exit "${FAKE_FAIL_CODE:-3}"
            fi
            SH);
        chmod($this->dir.'/bin/console', 0o700);
        file_put_contents($this->dir.'/bin/seed-demo', <<<'SH'
            #!/bin/sh
            echo "seed-demo $*" >> "$(dirname "$0")/calls.log"
            if [ "${FAKE_FAIL_ON:-}" = "seed-demo" ]; then
                echo "boom in seed-demo" >&2
                exit 4
            fi
            SH);
        chmod($this->dir.'/bin/seed-demo', 0o700);
    }

    protected function tearDown(): void
    {
        foreach (file_exists($this->dir.'/bin/hung.log') ? explode("\n", trim((string) file_get_contents($this->dir.'/bin/hung.log'))) : [] as $pid) {
            '' !== $pid && posix_kill((int) $pid, \SIGKILL);
        }
        foreach (glob($this->dir.'/bin/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir.'/bin');
        rmdir($this->dir);
    }

    public function testRunsTheStepsInTheOrderOfTheSpecification(): void
    {
        [$code] = $this->runScript(['--profile=small', '--anchor=2026-11-17T00:00:00Z', '--processes=2'], ['APP_ENV' => 'dev']);

        self::assertSame(0, $code);
        $calls = $this->calls();
        $common = '--demo-profile=small --anchor=2026-11-17T00:00:00Z';
        self::assertSame('demo-data:products '.$common.' --list-parts', $calls[0], 'The options are checked before anything is deleted.');
        self::assertSame('seed-demo', $calls[1]);
        self::assertSame('demo-data:categories '.$common, $calls[2]);
        $workers = \array_slice($calls, 3, 2);
        sort($workers);
        self::assertSame(['demo-data:products '.$common.' --worker=0 --workers=2', 'demo-data:products '.$common.' --worker=1 --workers=2'], $workers);
        self::assertSame('demo-data:photos '.$common, $calls[5]);
        $priceWorkers = \array_slice($calls, 6, 2);
        sort($priceWorkers);
        self::assertSame(['demo-data:prices '.$common.' --worker=0 --workers=2', 'demo-data:prices '.$common.' --worker=1 --workers=2'], $priceWorkers);
        self::assertSame(
            ['demo-data:promotions '.$common, 'demo-data:analyze', 'catalog:reindex', 'storefront:cache:purge-all', 'demo-data:verify '.$common],
            \array_slice($calls, 8),
        );
    }

    public function testTheDefaultsAreTheSmallProfileFourProcessesAndTheStartOfTheCurrentUtcDay(): void
    {
        [$code] = $this->runScript([], ['APP_ENV' => 'dev']);

        self::assertSame(0, $code);
        $calls = $this->calls();
        $workers = array_values(array_filter($calls, static fn (string $call): bool => str_starts_with($call, 'demo-data:products') && !str_contains($call, '--list-parts')));
        self::assertCount(4, $workers);
        self::assertMatchesRegularExpression('/^demo-data:categories --demo-profile=small --anchor=\d{4}-\d{2}-\d{2}T00:00:00Z$/D', $calls[2]);
        self::assertSame(1, preg_match('/--anchor=(\S+)/', $calls[2], $anchor));
        // Every step that takes the options gets the same anchor, taken once.
        foreach (array_filter($calls, static fn (string $call): bool => str_starts_with($call, 'demo-data:') && 'demo-data:analyze' !== $call) as $call) {
            self::assertStringContainsString('--anchor='.$anchor[1], $call);
        }
        foreach ($workers as $worker) {
            self::assertStringContainsString('--workers=4', $worker);
        }
    }

    public function testThePartsRunInParallel(): void
    {
        // Every part waits for the others: they can only all finish when they run at the same time.
        file_put_contents($this->dir.'/bin/console', <<<'SH'
            #!/bin/sh
            echo "$*" >> "$(dirname "$0")/calls.log"
            case "$*" in
                *--list-parts*) ;;
                demo-data:products*)
                    touch "$(dirname "$0")/started.$$"
                    n=0
                    while [ "$(ls "$(dirname "$0")" | grep -c '^started\.')" -lt 3 ]; do
                        n=$((n + 1)); [ "$n" -gt 100 ] && exit 9
                        sleep 0.1
                    done
                    ;;
            esac
            SH);

        [$code] = $this->runScript(['--processes=3'], ['APP_ENV' => 'dev']);

        self::assertSame(0, $code);
        foreach (glob($this->dir.'/bin/started.*') ?: [] as $file) {
            unlink($file);
        }
    }

    public function testNoStepGetsForceResetAndProdWithoutItIsRefused(): void
    {
        [$code, $stderr] = $this->runScript([], ['APP_ENV' => 'prod']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('--force-reset', $stderr);
        self::assertSame([], $this->calls(), 'Nothing runs when the script refuses.');
    }

    public function testProdWithForceResetRunsEverything(): void
    {
        [$code] = $this->runScript(['--force-reset', '--processes=1'], ['APP_ENV' => 'prod']);

        self::assertSame(0, $code);
        $calls = $this->calls();
        self::assertSame('seed-demo', $calls[1]);
        self::assertContains('demo-data:verify --demo-profile=small --anchor='.substr($calls[2], strpos($calls[2], '--anchor=') + 9), $calls);
    }

    public function testForceResetIsAcceptedOutsideProd(): void
    {
        [$code] = $this->runScript(['--force-reset', '--processes=1'], ['APP_ENV' => 'dev']);

        self::assertSame(0, $code);
    }

    /**
     * @return iterable<string, array{string, list<string>}> the call that fails and the calls that happened until it
     */
    public static function failingSteps(): iterable
    {
        $steps = ['check', 'seed-demo', 'demo-data:categories', 'demo-data:products', 'demo-data:photos', 'demo-data:prices', 'demo-data:promotions', 'demo-data:analyze', 'catalog:reindex', 'storefront:cache:purge-all', 'demo-data:verify'];
        foreach (['seed-demo', 'demo-data:categories', 'demo-data:photos', 'demo-data:prices', 'demo-data:promotions', 'demo-data:analyze', 'catalog:reindex', 'storefront:cache:purge-all', 'demo-data:verify'] as $failing) {
            yield $failing => [$failing, \array_slice($steps, 0, array_search($failing, $steps, true) + 1)];
        }
        // The check of the options, which has no name of its own: it is the products command asked for the parts.
        yield 'check of the options' => ['--list-parts', ['check']];
    }

    /**
     * @param list<string> $expected the commands that ran, by name, in order (the parts count once)
     *
     * @dataProvider failingSteps
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failingSteps')]
    public function testAFailingStepStopsTheScriptWithItsCodeAndRunsNothingAfterIt(string $failing, array $expected): void
    {
        [$code, $stderr] = $this->runScript(['--processes=1'], ['APP_ENV' => 'dev', 'FAKE_FAIL_ON' => $failing]);

        self::assertSame('seed-demo' === $failing ? 4 : 3, $code);
        self::assertStringContainsString('boom in', $stderr);
        self::assertSame($expected, array_values(array_unique($this->names())));
    }

    public function testAFailingPartStopsTheOtherPartsAndTheFollowingSteps(): void
    {
        $started = microtime(true);

        [$code, $stderr] = $this->runScript(
            ['--processes=3'],
            ['APP_ENV' => 'dev', 'FAKE_FAIL_ON' => '--worker=1', 'FAKE_FAIL_CODE' => '7'],
            // The parts 0 and 2 would run for a minute: the script has to stop them.
            hang: '--worker=0|--worker=2',
        );

        self::assertSame(7, $code);
        self::assertStringContainsString('boom in demo-data:products', $stderr);
        self::assertLessThan(20.0, microtime(true) - $started, 'The script waited for the other parts.');
        $names = $this->names();
        self::assertNotContains('demo-data:photos', $names);
        self::assertNotContains('catalog:reindex', $names);
        self::assertNotContains('demo-data:verify', $names);

        $hung = file_exists($this->dir.'/bin/hung.log') ? array_filter(explode("\n", trim((string) file_get_contents($this->dir.'/bin/hung.log')))) : [];
        self::assertCount(2, $hung, 'Both other parts had started.');
        foreach ($hung as $pid) {
            self::assertFalse(posix_kill((int) $pid, 0), 'The part with process id '.$pid.' is still running.');
        }
    }

    public function testAFailingPriceStepPartStopsTheOtherPartsAndTheFollowingSteps(): void
    {
        $started = microtime(true);
        $prices = 'demo-data:prices --demo-profile=small --anchor=2026-11-17T00:00:00Z --worker=';

        [$code, $stderr] = $this->runScript(
            ['--processes=3', '--anchor=2026-11-17T00:00:00Z'],
            ['APP_ENV' => 'dev', 'FAKE_FAIL_ON' => $prices.'1', 'FAKE_FAIL_CODE' => '7'],
            hang: $prices.'0|'.$prices.'2',
        );

        self::assertSame(7, $code);
        self::assertStringContainsString('boom in demo-data:prices', $stderr);
        self::assertLessThan(20.0, microtime(true) - $started, 'The script waited for the other parts.');
        $names = $this->names();
        self::assertContains('demo-data:photos', $names, 'The steps before the prices ran.');
        self::assertNotContains('demo-data:promotions', $names);
        self::assertNotContains('demo-data:analyze', $names);
        self::assertNotContains('demo-data:verify', $names);

        $hung = file_exists($this->dir.'/bin/hung.log') ? array_filter(explode("\n", trim((string) file_get_contents($this->dir.'/bin/hung.log')))) : [];
        self::assertCount(2, $hung, 'Both other parts had started.');
        foreach ($hung as $pid) {
            self::assertFalse(posix_kill((int) $pid, 0), 'The part with process id '.$pid.' is still running.');
        }
    }

    public function testAnInvalidProcessCountIsRefused(): void
    {
        foreach (['--processes=0', '--processes=abc', '--processes=-1', '--processes='] as $argument) {
            file_exists($this->dir.'/bin/calls.log') && unlink($this->dir.'/bin/calls.log');

            [$code, $stderr] = $this->runScript([$argument], ['APP_ENV' => 'dev']);

            self::assertSame(2, $code, $argument);
            self::assertStringContainsString('--processes', $stderr);
            self::assertSame([], $this->calls(), $argument);
        }
    }

    public function testAnUnknownOptionIsRefusedWithTheUsage(): void
    {
        [$code, $stderr] = $this->runScript(['--turbo'], ['APP_ENV' => 'dev']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Usage', $stderr);
        self::assertSame([], $this->calls());
    }

    public function testAProfileThatIsNotAWordIsRefused(): void
    {
        [$code] = $this->runScript(['--profile=small; rm -rf /'], ['APP_ENV' => 'dev']);

        self::assertSame(2, $code);
        self::assertSame([], $this->calls());
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     * @param string|null           $hang      `|`-separated parts of the arguments of the calls that wait for a minute
     *
     * @return array{int, string} exit code and standard error
     */
    private function runScript(array $arguments, array $env, ?string $hang = null): array
    {
        $calls = [];
        foreach (explode('|', $hang ?? '') as $part) {
            '' !== $part && $calls[] = $part;
        }
        if ([] !== $calls) {
            // One fake console that hangs on every named call: rewrite it with a case on the arguments.
            $patterns = implode('|', array_map(static fn (string $part): string => '*"'.$part.'"*', $calls));
            file_put_contents($this->dir.'/bin/console', <<<SH
                #!/bin/sh
                echo "\$*" >> "\$(dirname "\$0")/calls.log"
                case "\$*" in
                    $patterns)
                        echo "\$\$" >> "\$(dirname "\$0")/hung.log"
                        exec sleep 60
                        ;;
                esac
                if [ -n "\${FAKE_FAIL_ON:-}" ] && case "\$*" in *"\$FAKE_FAIL_ON"*) true;; *) false;; esac; then
                    echo "boom in \$*" >&2
                    exit "\${FAKE_FAIL_CODE:-3}"
                fi
                SH);
        }

        $process = proc_open(
            ['/bin/sh', $this->dir.'/bin/generate-demo', ...$arguments],
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
     * @return list<string> the command of every call; the check of the options (the products command asked for the parts) is `check`
     */
    private function names(): array
    {
        return array_map(static fn (string $call): string => str_contains($call, '--list-parts') ? 'check' : explode(' ', $call)[0], $this->calls());
    }

    /**
     * @return list<string>
     */
    private function calls(): array
    {
        $log = @file_get_contents($this->dir.'/bin/calls.log');

        return false === $log ? [] : array_map('rtrim', explode("\n", trim($log)));
    }
}
