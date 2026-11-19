<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Console;

use App\Shared\UI\Console\RateLimitCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * rate-limit:check on a Redis double (spec: docs/features/abuse-limits/spec.md, L12, D16): a control key written with a
 * time to live, read and deleted; every other outcome is an exit code other than 0 with the reason in the output and no
 * exception.
 */
final class RateLimitCheckCommandTest extends TestCase
{
    public function testAWorkingRedisIsExitCodeZeroAndTheKeyIsWrittenWithATimeToLiveReadAndDeleted(): void
    {
        $written = [];
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('set')->willReturnCallback(static function (string $key, mixed $value, mixed $options = null) use (&$written): bool {
            $written = ['key' => $key, 'value' => $value, 'options' => $options];

            return true;
        });
        $redis->expects(self::once())->method('get')->willReturnCallback(static fn (string $key): string|false => $written['key'] === $key ? $written['value'] : false);
        $redis->expects(self::once())->method('del')->willReturnCallback(static function (string $key) use (&$written): int {
            self::assertSame($written['key'], $key);

            return 1;
        });

        $tester = $this->tester($redis);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertMatchesRegularExpression('/^rate-limit-check:[0-9a-f]{32}$/', $written['key'], 'a key of its own, outside the keys of the limiters and the locks');
        self::assertIsArray($written['options']);
        self::assertIsInt($written['options']['EX'] ?? null);
        self::assertGreaterThanOrEqual(1, $written['options']['EX']);
        self::assertLessThanOrEqual(3600, $written['options']['EX']);
    }

    public function testEveryRunWritesAnotherKeyAndValue(): void
    {
        $keys = [];
        $values = [];
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$keys, &$values): bool {
            $keys[] = $key;
            $values[$key] = $value;

            return true;
        });
        $redis->method('get')->willReturnCallback(static fn (string $key): string => $values[$key]);
        $redis->method('del')->willReturn(1);
        $tester = $this->tester($redis);

        $tester->execute([]);
        $tester->execute([]);

        self::assertCount(2, array_unique($keys));
        self::assertCount(2, array_unique($values));
    }

    public function testARejectedWriteIsAnExitCodeOtherThanZeroWithTheReasonAndNoException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willThrowException(new \RedisException('MISCONF Redis is configured to save RDB snapshots, but it is currently not able to persist on disk.'));
        $redis->expects(self::never())->method('get');

        $tester = $this->tester($redis);

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('MISCONF', $this->display($tester));
    }

    public function testAWriteThatRedisAnswersWithFalseIsAnExitCodeOtherThanZero(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturn(false);
        $redis->expects(self::never())->method('get');

        $tester = $this->tester($redis);

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('write', $this->display($tester));
    }

    public function testAnotherValueThanTheOneWrittenIsAnExitCodeOtherThanZeroWithTheReason(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturn(true);
        $redis->method('get')->willReturn('another value');

        $tester = $this->tester($redis);

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('another value than the one written', $this->display($tester));
    }

    public function testAKeyThatIsNotThereOnReadIsAnExitCodeOtherThanZero(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturn(true);
        $redis->method('get')->willReturn(false);

        $tester = $this->tester($redis);

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('not there', $this->display($tester));
    }

    public function testADeleteThatFailsOrDeletesNothingIsAnExitCodeOtherThanZero(): void
    {
        foreach (['an error' => new \RedisException('READONLY You can\'t write against a read only replica.'), 'nothing deleted' => 0] as $case => $outcome) {
            $value = null;
            $redis = $this->createMock(\Redis::class);
            $redis->method('set')->willReturnCallback(static function (string $key, mixed $written) use (&$value): bool {
                $value = $written;

                return true;
            });
            $redis->method('get')->willReturnCallback(static fn (): mixed => $value);
            $redis->method('del')->willReturnCallback(static fn (): int => $outcome instanceof \Throwable ? throw $outcome : $outcome);
            $tester = $this->tester($redis);

            self::assertSame(2, $tester->execute([]), $case);
            self::assertStringContainsString('delete', $this->display($tester), $case);
        }
    }

    public function testAConnectionThatCannotBeMadeIsAnExitCodeOtherThanZeroWithTheReasonAndNoException(): void
    {
        $tester = $this->testerOfConnection(static fn (): \Redis => throw new \RedisException('Connection refused'));

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('Connection refused', $this->display($tester));
    }

    public function testAnErrorThatIsNotOfRedisIsHandledToo(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willThrowException(new \TypeError('unexpected'));

        $tester = $this->tester($redis);

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('unexpected', $this->display($tester));
    }

    private function tester(\Redis $redis): CommandTester
    {
        return $this->testerOfConnection(static fn (): \Redis => $redis);
    }

    /**
     * @param \Closure(): \Redis $connection
     */
    private function testerOfConnection(\Closure $connection): CommandTester
    {
        $application = new Application();
        $application->addCommand(new RateLimitCheckCommand($connection));

        return new CommandTester($application->find('rate-limit:check'));
    }

    private function display(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }
}
