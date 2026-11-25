<?php
// Experiment: CacheInterface::get() of a FilesystemAdapter under concurrent requests (stampede protection of LockRegistry).
require '/app/vendor/autoload.php';
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;
$key = $_GET['key'] ?? 'facets';
$fail = isset($_GET['fail']);
$pool = new FilesystemAdapter('exp', 300, '/shared/cache');
$t0 = microtime(true);
$computed = false;
$value = $pool->get($key, function (ItemInterface $item) use (&$computed, $fail, $key) {
    $computed = true;
    file_put_contents('/shared/computes.log', getmypid() . ' ' . $key . ' ' . microtime(true) . "\n", FILE_APPEND | LOCK_EX);
    usleep(2_000_000); // a facets query of 2 s
    if ($fail) { throw new RuntimeException('facets failed'); }
    return ['facets' => $key];
});
header('Content-Type: text/plain');
printf("%s computed=%d wait=%.2f pid=%d\n", $key, $computed ? 1 : 0, microtime(true) - $t0, getmypid());
