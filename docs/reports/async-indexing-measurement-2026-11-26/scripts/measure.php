<?php
// Temporary, uncommitted measurement script (ASYNC-INDEXING task 1). Usage:
//   php var/measure/measure.php components <batchSize> <batches>
//   php var/measure/measure.php writer <batchSize> <batches> <startOffsetIds>   (writes to its own new index)
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Catalog\Application\Port\{ProductOfferFacts, ProductSearchSource, SearchIndexMaintenance};
use App\Catalog\Application\Search\{ProductDocumentBuilder, ProductIndexing, ProductSearchSnapshot};
use App\Kernel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\{ContainerBuilder};
use Symfony\Component\DependencyInjection\Compiler\PassConfig;

final class MeasureKernel extends Kernel
{
    protected function build(ContainerBuilder $c): void
    {
        parent::build($c);
        $c->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $c): void
            {
                foreach ([ProductOfferFacts::class, ProductSearchSource::class, SearchIndexMaintenance::class, ProductDocumentBuilder::class] as $id) {
                    $c->hasAlias($id) ? $c->getAlias($id)->setPublic(true) : $c->getDefinition($id)->setPublic(true);
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function getCacheDir(): string { return dirname(__DIR__).'/cache/measure'; }
}

[, $mode, $size, $count] = $argv + [null, 'components', 500, 20];
$size = (int) $size; $count = (int) $count; $skip = (int) ($argv[4] ?? 0);
$k = new MeasureKernel(getenv('APP_ENV') ?: 'dev', false); $k->boot(); $c = $k->getContainer();
$source = $c->get(ProductSearchSource::class); $offers = $c->get(ProductOfferFacts::class);
$builder = $c->get(ProductDocumentBuilder::class); $maint = $c->get(SearchIndexMaintenance::class);
$clock = static fn (): float => microtime(true);

// Position the cursor after $skip batches (reads only ids via page of the same size; not timed).
$after = null;
for ($i = 0; $i < $skip; $i++) { $p = $source->page($after, $size); $after = $p[array_key_last($p)]->id; }

$index = 'measure_'.date('His').'_'.getmypid();
if ('writer' === $mode) { $index = $maint->createIndex(); }
while (microtime(true) < (float) getenv('START_AT')) { usleep(1000); }
$t0 = microtime(true);
$t = ['page' => 0.0, 'offers' => 0.0, 'build' => 0.0, 'write' => 0.0]; $docs = 0; $wall0 = $clock();
$perBatch = [];
for ($i = 0; $i < $count; $i++) {
    $a = $clock(); $page = $source->page($after, $size); $b = $clock();
    if ([] === $page) { break; }
    $facts = $offers->of(array_map(static fn (ProductSearchSnapshot $s): string => $s->id, $page)); $c2 = $clock();
    $v = ProductIndexing::version(new DateTimeImmutable());
    $documents = array_map(static fn ($s) => $builder->build($s, $facts[$s->id] ?? null, $v), $page); $d = $clock();
    $r = 'components' === $mode ? null : $maint->write($index, $documents); $e = $clock();
    if (null !== $r && !$r->allIndexed()) { fwrite(STDERR, 'bulk problems: '.$r->describeProblems()."\n"); }
    $t['page'] += $b - $a; $t['offers'] += $c2 - $b; $t['build'] += $d - $c2; $t['write'] += $e - $d;
    $perBatch[] = [round($b - $a, 4), round($c2 - $b, 4), round($d - $c2, 4), round($e - $d, 4)];
    $docs += count($page); $after = $page[array_key_last($page)]->id;
}
$wall = $clock() - $wall0;
echo json_encode(['mode' => $mode, 'pid' => getmypid(), 'index' => $index, 'batch' => $size, 'batches' => count($perBatch), 'docs' => $docs,
    'start' => round($t0, 2), 'end' => round(microtime(true), 2), 'wall_s' => round($wall, 2), 'docs_per_s' => round($docs / $wall, 1), 'sum_s' => array_map(static fn ($x) => round($x, 3), $t),
    'per_batch_mean_ms' => array_map(static fn ($x) => round($x / max(1, count($perBatch)) * 1000, 1), $t)], JSON_UNESCAPED_SLASHES)."\n";
