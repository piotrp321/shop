\d ordering_sales_hour
\d inventory_stock_item
\echo '=== D1 sales 60 days (30 + previous 30)'
EXPLAIN (ANALYZE, BUFFERS) SELECT CAST(COALESCE(SUM(order_count), 0) AS bigint), CAST(COALESCE(SUM(gross), 0) AS bigint) FROM ordering_sales_hour WHERE hour_start >= now() - interval '60 days' AND hour_start < now();
\echo '=== D2 out of stock count (limit 1000)'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM inventory_stock_item WHERE physical - reserved = 0 AND product_id IS NOT NULL LIMIT 1001) bounded;
\echo '=== D3 low stock count'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM inventory_stock_item WHERE physical - reserved BETWEEN 1 AND 5 AND product_id IS NOT NULL LIMIT 1001) bounded;
\echo '=== D4 products without images (limit 1000)'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.images = '[]'::jsonb LIMIT 1001) bounded;
\echo '=== D5 recent orders (perPage 5)'
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) FROM ordering_order o ORDER BY o.placed_at DESC, o.id DESC LIMIT 6;
select count(*) filter (where physical-reserved=0) oos, count(*) filter (where physical-reserved between 1 and 5) low from inventory_stock_item;
