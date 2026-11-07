#!/bin/sh
# The job `initialize`: loads the demo data into an empty database, once.
#
# It reads the number of products, prices, stocks and orders (read-only queries) and decides:
#   all four empty                      -> loads the demo data with bin/seed-demo (APP_ENV=prod: --force-reset)
#   products, prices and stocks present -> nothing to do (orders do not matter: there are none after a seed)
#   anything else                       -> fails with the state and changes nothing: an empty catalog with orders or
#                                          prices would lose them to the reset of the seed; products without prices or
#                                          stocks mean an interrupted initialization, which needs a human decision
# Runs in the working directory of the API image (/app).
set -eu

query="SELECT concat('products=', (SELECT count(*) FROM catalog_product), ' prices=', (SELECT count(*) FROM pricing_variant_price), ' stocks=', (SELECT count(*) FROM inventory_stock_item), ' orders=', (SELECT count(*) FROM ordering_order)) AS state"

answer="$(bin/console dbal:run-sql "$query")"
state="$(printf '%s\n' "$answer" | grep -o 'products=[0-9]* prices=[0-9]* stocks=[0-9]* orders=[0-9]*' | head -n 1 || true)"
if [ -z "$state" ]; then
  echo "initialize: cannot read the state of the database from: $answer" >&2
  exit 1
fi

products="$(echo "$state" | sed 's/.*products=\([0-9]*\).*/\1/')"
prices="$(echo "$state" | sed 's/.*prices=\([0-9]*\).*/\1/')"
stocks="$(echo "$state" | sed 's/.*stocks=\([0-9]*\).*/\1/')"
orders="$(echo "$state" | sed 's/.*orders=\([0-9]*\).*/\1/')"

if [ "$products" -eq 0 ] && [ "$prices" -eq 0 ] && [ "$stocks" -eq 0 ] && [ "$orders" -eq 0 ]; then
  echo "initialize: the database is empty ($state), loading the demo data"
  exec bin/seed-demo
fi

if [ "$products" -gt 0 ] && [ "$prices" -gt 0 ] && [ "$stocks" -gt 0 ]; then
  echo "initialize: the data is there ($state), nothing to do"
  exit 0
fi

echo "initialize: the database is in a mixed state ($state): it is neither empty nor loaded." >&2
echo "initialize: nothing was changed. An interrupted initialization is repaired by 'make -C infra/k8s reinitialize'" >&2
echo "initialize: (it wipes the data and needs the owner's consent); orders or prices without a catalog need a look first." >&2
exit 1
