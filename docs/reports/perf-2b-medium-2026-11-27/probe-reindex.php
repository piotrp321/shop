<?php

// PERF-2B-8: memory of `catalog:reindex` per batch, in any mode of the kernel. Not part of the application: it is read from
// the standard input by PHP inside the api container (run.sh, `probe`), so nothing is added to /app.
//
//   PROBE_LABEL=name PROBE_LIMIT=300000 PROBE_OUT=/tmp/probe-name.jsonl APP_ENV=dev APP_DEBUG=1 php -d memory_limit=256M - < probe-reindex.php
//
// A Monolog processor on the channel search_rebuild (the log "Search index rebuild progress" of every batch) writes one line per
// batch: documents done, memory_get_usage(), the peak and, every 10th batch, the memory after gc_collect_cycles() (a cycle that
// waits for the collector against an object that is still referenced), the number of recorded queries of the Doctrine
// DebugDataHolder and the number of periods of the Stopwatch (both exist only with kernel.debug). At PROBE_LIMIT documents the
// PROBE_RESET=holder or stopwatch empties that collector after every batch (an experiment: which of them holds the memory).
// At PROBE_LIMIT documents the processor throws, so the rebuilder discards the new index and the alias stays where it was (an unfinished rebuild leaves
// nothing). Without PROBE_LIMIT the command runs to the end.

declare(strict_types=1);

use App\Kernel;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Dotenv\Dotenv;

require '/app/vendor/autoload.php';

final class ProbeProcessor
{
    private int $batches = 0;

    // The settings come from the environment at run time, never through the arguments: a container compiled by an earlier run
    // would keep their values.
    public function __construct(private readonly ?object $holder, private readonly ?object $stopwatch)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ('Search index rebuild progress.' !== $record->message) {
            return $record;
        }
        ++$this->batches;
        // PROBE_RESET=holder|stopwatch empties that collector after every batch (an experiment: which one holds the memory).
        $reset = (string) getenv('PROBE_RESET');
        if ('holder' === $reset && null !== $this->holder) {
            $this->holder->reset();
        }
        if ('stopwatch' === $reset && null !== $this->stopwatch) {
            $this->stopwatch->reset();
        }
        $line = [
            'label' => getenv('PROBE_LABEL'),
            'batch' => $this->batches,
            'documents' => $record->context['documents'] ?? null,
            'usage' => memory_get_usage(),
            'peak' => memory_get_peak_usage(),
        ];
        if (0 === $this->batches % 10) {
            $line['collected'] = gc_collect_cycles();
            $line['usage_after_gc'] = memory_get_usage();
            if (null !== $this->holder) {
                $queries = 0;
                $sqlBytes = 0;
                $paramBytes = 0;
                foreach ($this->holder->getData() as $connection) {
                    foreach ($connection as $query) {
                        ++$queries;
                        $sqlBytes += \strlen((string) ($query['sql'] ?? ''));
                        $paramBytes += \strlen((string) json_encode($query['params'] ?? [], \JSON_PARTIAL_OUTPUT_ON_ERROR));
                    }
                }
                $line += ['dbal_queries' => $queries, 'dbal_sql_bytes' => $sqlBytes, 'dbal_param_bytes' => $paramBytes];
            }
            if (null !== $this->stopwatch) {
                $events = 0;
                $periods = 0;
                foreach ($this->stopwatch->getSections() as $section) {
                    foreach ($section->getEvents() as $event) {
                        ++$events;
                        $periods += \count($event->getPeriods());
                    }
                }
                $line += ['stopwatch_events' => $events, 'stopwatch_periods' => $periods];
            }
        }
        file_put_contents((string) getenv('PROBE_OUT'), json_encode($line)."\n", \FILE_APPEND);
        if ((int) getenv('PROBE_LIMIT') > 0 && ($record->context['documents'] ?? 0) >= (int) getenv('PROBE_LIMIT')) {
            throw new \RuntimeException('probe limit of documents reached');
        }

        return $record;
    }
}

final class ProbeKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $optional = ContainerInterface::NULL_ON_INVALID_REFERENCE;
                $container->register('probe.processor', ProbeProcessor::class)
                    ->setArguments([
                        new Reference('doctrine.debug_data_holder', $optional),
                        new Reference('debug.stopwatch', $optional),
                    ])
                    ->addTag('monolog.processor', ['channel' => 'search_rebuild']);
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
    }
}

(new Dotenv())->bootEnv('/app/.env');
$env = $_SERVER['APP_ENV'];
$debug = filter_var($_SERVER['APP_DEBUG'] ?? '0', \FILTER_VALIDATE_BOOLEAN);
@unlink((string) getenv('PROBE_OUT'));
$application = new Application(new ProbeKernel($env, $debug));
$application->setAutoExit(false);
$output = new BufferedOutput();
$code = $application->run(new ArrayInput(['command' => 'catalog:reindex']), $output);
fwrite(\STDERR, sprintf("probe %s: env %s debug %s exit %d peak %d bytes\n%s\n", getenv('PROBE_LABEL'), $env, var_export($debug, true), $code, memory_get_peak_usage(), substr($output->fetch(), -600)));
exit($code);
