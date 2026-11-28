<?php
$pdo = new PDO('pgsql:host='.getenv('SHOP_DB_HOST').';dbname='.getenv('SHOP_DB_NAME'), getenv('SHOP_DB_USER'), getenv('SHOP_DB_PASSWORD'), [PDO::ATTR_PERSISTENT => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET application_name = 'perf4-txn-test'");
$pdo->beginTransaction();
$pdo->query('SELECT id FROM cart_cart LIMIT 1 FOR UPDATE');
if (($_GET['fatal'] ?? '') === '1') { trigger_error('boom', E_USER_ERROR); }
echo "left open\n";
