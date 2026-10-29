<?php

declare(strict_types=1);

// Entity manager for phpstan-doctrine (DQL and repository checks). Builds the manager without connecting.

use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;

require __DIR__.'/bootstrap.php';

$kernel = new Kernel('test', false);
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');
assert($doctrine instanceof ManagerRegistry);

return $doctrine->getManager();
