<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductIndexing;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Search\ElasticsearchClientFactory;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\SystemClock;
use App\Tests\Support\InMemory\CallbackProductSearchSource;
use App\Tests\Support\InMemory\InMemoryProductOfferFacts;

/**
 * A process of its own (pcntl_fork) that changes products and reindexes every change through the real indexer, in
 * a loop, until it is stopped: the "database" is one file per product with the number of its last state, replaced
 * atomically (rename), so the rebuild of the parent process and the writer read committed states only. A change is
 * committed before it is reindexed (the event comes after the commit), and a write that fails transiently is retried,
 * as Messenger retries the message; after 20 attempts, or on any other failure, the writer stops with the failure.
 *
 * The parent pauses the writer between two changes: once paused, every change it committed has been written. The child
 * writes its outcome to a file and kills itself (SIGKILL), so it never runs the destructors it inherited, which would
 * close the parent's connections.
 */
final class ParallelSearchWriter
{
    private const float TIMEOUT_SECONDS = 15.0;
    private const int WRITE_ATTEMPTS = 20;
    private const string CATEGORY = '0198a1f0-0000-7000-8000-0000000000c1';

    private string $directory;
    private string $url;
    private ?int $pid = null;

    /**
     * @param list<string> $ids lowercase UUIDs
     */
    public function __construct(private readonly array $ids, private readonly string $alias)
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            throw new \RuntimeException('The concurrency test needs the pcntl and posix extensions.');
        }
        $url = $_SERVER['ELASTICSEARCH_URL'] ?? getenv('ELASTICSEARCH_URL');
        if (!\is_string($url)) {
            throw new \RuntimeException('ELASTICSEARCH_URL is not set.');
        }
        $this->url = $url;
        $this->directory = sys_get_temp_dir().'/search-writer-'.bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0o700)) {
            throw new \RuntimeException('Cannot create the directory of the writer.');
        }
        foreach ($ids as $id) {
            $this->replace($this->stateFile($id), '0');
        }
        $this->replace($this->file('writes'), '0');
    }

    public static function snapshot(string $id, string $name): ProductSearchSnapshot
    {
        return new ProductSearchSnapshot($id, $name, 'slug-'.$id, '', self::CATEGORY, [], [[self::CATEGORY]], null, [], [], [['id' => '1'.substr($id, 1), 'values' => []]], ProductStatus::Published);
    }

    /**
     * The committed state of the product, as the database would return it.
     */
    public function snapshotOf(string $id): ProductSearchSnapshot
    {
        return self::snapshot($id, $this->nameOf($id));
    }

    public function nameOf(string $id): string
    {
        return \sprintf('State %d of %s', $this->read($this->stateFile($id)), $id);
    }

    /**
     * Starts the writer and returns once it has written its first change.
     */
    public function start(): void
    {
        // Garbage cycles the parent has not collected yet would be collected (and their destructors run) in the child.
        gc_collect_cycles();
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('Cannot fork the writer.');
        }
        if (0 === $pid) {
            $this->write();
        }
        $this->pid = $pid;
        $this->await(fn (): bool => $this->writes() > 0, 'the first write');
    }

    /**
     * @return int the changes written so far
     */
    public function writes(): int
    {
        return $this->read($this->file('writes'));
    }

    /**
     * Returns once the writer waits between two changes.
     */
    public function pause(): void
    {
        touch($this->file('pause'));
        $this->await(fn (): bool => $this->exists($this->file('paused')), 'the pause');
    }

    public function resume(): void
    {
        unlink($this->file('pause'));
        $this->await(fn (): bool => !$this->exists($this->file('paused')), 'the end of the pause');
    }

    /**
     * Stops the writer (killing it when it does not stop in time) and removes its files.
     *
     * @return array{writes: int, retries: int, failures: list<string>}
     */
    public function stop(): array
    {
        touch($this->file('stop'));
        $outcome = ['writes' => 0, 'retries' => 0, 'failures' => ['The writer left no outcome.']];
        if (null !== $this->pid) {
            $deadline = microtime(true) + self::TIMEOUT_SECONDS;
            while (0 === pcntl_waitpid($this->pid, $status, \WNOHANG) && microtime(true) < $deadline) {
                usleep(5_000);
            }
            if (microtime(true) >= $deadline) {
                posix_kill($this->pid, \SIGKILL);
                pcntl_waitpid($this->pid, $status);
            }
            $this->pid = null;
            $content = $this->exists($this->file('outcome')) ? file_get_contents($this->file('outcome')) : false;
            if (\is_string($content) && '' !== $content) {
                /** @var array{writes: int, retries: int, failures: list<string>} $outcome */
                $outcome = json_decode($content, true, 8, \JSON_THROW_ON_ERROR);
            }
        }
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);

        return $outcome;
    }

    /**
     * The loop of the child. Never returns: the process kills itself.
     */
    private function write(): never
    {
        ini_set('display_errors', '0');
        register_shutdown_function(static function (): void {
            posix_kill(posix_getpid(), \SIGKILL);
        });

        $writes = $retries = 0;
        $failures = [];
        try {
            $indexing = new ProductIndexing(
                new CallbackProductSearchSource($this->ids, $this->snapshotOf(...)),
                new InMemoryProductOfferFacts(),
                new ElasticsearchProductIndexer(new ElasticsearchClientFactory($this->url, 1.0)->create(2.0), $this->alias),
                new ProductDocumentBuilder(),
                new SystemClock(),
            );
            for ($change = 0; !$this->exists($this->file('stop')); ++$change) {
                if ($this->exists($this->file('pause'))) {
                    touch($this->file('paused'));
                    while ($this->exists($this->file('pause')) && !$this->exists($this->file('stop'))) {
                        usleep(1_000);
                    }
                    unlink($this->file('paused'));
                    continue;
                }
                $id = $this->ids[$change % \count($this->ids)];
                $this->replace($this->stateFile($id), (string) ($this->read($this->stateFile($id)) + 1));
                for ($attempt = 1;; ++$attempt) {
                    try {
                        $indexing->reindex($id);
                        break;
                    } catch (SearchUnavailable $e) {
                        ++$retries;
                        if ($attempt >= self::WRITE_ATTEMPTS) {
                            $failures[] = \sprintf('%s: %d attempts, the last: %s', $id, $attempt, $e->getMessage());
                            break;
                        }
                        usleep(10_000);
                    }
                }
                $this->replace($this->file('writes'), (string) ++$writes);
                if ([] !== $failures) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $failures[] = $e::class.': '.$e->getMessage();
        }

        file_put_contents($this->file('outcome'), json_encode(['writes' => $writes, 'retries' => $retries, 'failures' => $failures], \JSON_THROW_ON_ERROR));
        posix_kill(posix_getpid(), \SIGKILL);
        exit(1);
    }

    /**
     * @param \Closure(): bool $done
     */
    private function await(\Closure $done, string $what): void
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (!$done()) {
            if (null !== $this->pid && 0 !== pcntl_waitpid($this->pid, $status, \WNOHANG)) {
                $this->pid = null;
                $content = $this->exists($this->file('outcome')) ? file_get_contents($this->file('outcome')) : false;
                throw new \RuntimeException(\sprintf('The writer ended while waiting for %s: %s', $what, \is_string($content) ? $content : 'no outcome'));
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(\sprintf('The writer did not reach %s in time.', $what));
            }
            usleep(1_000);
        }
    }

    /**
     * The stat cache of PHP would keep answering for a file that the other process created or deleted.
     *
     * @phpstan-impure
     */
    private function exists(string $file): bool
    {
        clearstatcache(true, $file);

        return is_file($file);
    }

    private function stateFile(string $id): string
    {
        return $this->file('state-'.$id);
    }

    private function file(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    private function read(string $file): int
    {
        $content = file_get_contents($file);
        if (!\is_string($content) || 1 !== preg_match('/^\d+$/D', $content)) {
            throw new \RuntimeException(\sprintf('The writer file "%s" is unreadable.', $file));
        }

        return (int) $content;
    }

    /**
     * Replaces the content atomically: a reader sees the old content or the new one, never a part of it.
     */
    private function replace(string $file, string $content): void
    {
        $temporary = $file.'.'.getmypid().'.tmp';
        if (false === file_put_contents($temporary, $content) || !rename($temporary, $file)) {
            throw new \RuntimeException(\sprintf('Cannot write the writer file "%s".', $file));
        }
    }
}
