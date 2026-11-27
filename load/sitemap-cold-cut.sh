#!/bin/sh
# Time of the cold cut of the sitemap chunk boundaries (PERF-7, G2, budget 2000 ms): runs
# DbalSitemapProductSource::boundaries() on the database of the stack, outside HTTP and without the cache, RUNS times
# (default 5), and prints one line per run plus a summary with the hash of the ids, so the result before and after a change
# of the cut can be compared (the ids must be equal) from the same data.
# Usage (from the repository root, stack up with `make up`; the Compose project comes from .env or COMPOSE_PROJECT_NAME):
#   COMPOSE_PROJECT_NAME=<project> sh load/sitemap-cold-cut.sh
# Variables: RUNS (5), CHUNK_SIZE (10000, as `app.sitemap.chunk_size`), ROW_LIMIT (50000, as `app.sitemap.row_limit`).
# Exit: 0 the lines were printed; 2 no running `api` container of the stack; otherwise the exit code of PHP.
set -eu

export RUNS="${RUNS:-5}" CHUNK_SIZE="${CHUNK_SIZE:-10000}" ROW_LIMIT="${ROW_LIMIT:-50000}"

if ! docker compose exec -T api true >/dev/null 2>&1; then
  echo "sitemap-cold-cut: no running api container (start the stack with make up)" >&2
  exit 2
fi

docker compose exec -T -e RUNS -e CHUNK_SIZE -e ROW_LIMIT api php <<'PHP'
<?php
require '/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/app/.env');
$kernel = new App\Kernel((string) $_SERVER['APP_ENV'], false);
$kernel->boot();
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
$products = (int) $connection->fetchOne('SELECT count(*) FROM catalog_product');
$chunk = (int) getenv('CHUNK_SIZE');
$source = new App\Catalog\Infrastructure\Persistence\Doctrine\DbalSitemapProductSource($connection, (int) getenv('ROW_LIMIT'), new Psr\Log\NullLogger());
$times = [];
$hash = '';
for ($run = 1; $run <= (int) getenv('RUNS'); ++$run) {
    $start = hrtime(true);
    $boundaries = $source->boundaries($chunk);
    $ms = (hrtime(true) - $start) / 1e6;
    $times[] = $ms;
    $hash = sha1(implode(',', $boundaries));
    printf("run %d: %.1f ms, %d boundaries\n", $run, $ms, count($boundaries));
}
sort($times);
printf("products %d, chunk %d: median %.1f ms, min %.1f ms, max %.1f ms, ids sha1 %s\n", $products, $chunk, $times[intdiv(count($times), 2)], $times[0], $times[count($times) - 1], $hash);
PHP
