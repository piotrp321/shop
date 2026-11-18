\pset pager off
\timing on
-- Parameters picked from the data.
SELECT path || '%' AS small_prefix, id AS small_cat FROM catalog_category c
 WHERE NOT EXISTS (SELECT 1 FROM catalog_category x WHERE x.parent_id = c.id)
 ORDER BY (SELECT count(*) FROM catalog_product p WHERE p.primary_category_id = c.id) ASC, path LIMIT 1 \gset
SELECT path || '%' AS big_prefix FROM catalog_category c WHERE parent_id IS NULL
 ORDER BY (SELECT count(*) FROM catalog_product p JOIN catalog_category cc ON cc.id = p.primary_category_id WHERE cc.path LIKE c.path || '%') DESC LIMIT 1 \gset
SELECT code_id AS hot_code FROM pricing_discount_code_usage GROUP BY code_id ORDER BY count(*) DESC LIMIT 1 \gset
SELECT id AS mid_product FROM catalog_product ORDER BY id OFFSET 500000 LIMIT 1 \gset
\echo small_prefix :small_prefix big_prefix :big_prefix hot_code :hot_code

\echo ===== E1a orders fragment frequent ('example')
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross, o.currency,
       (SELECT COALESCE(SUM(l.quantity), 0) FROM ordering_order_line l WHERE l.order_id = o.id) AS item_count,
       o.refund_amount, o.refund_due_at, o.refund_completed_at
FROM ordering_order o
WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('example') || '%'
ORDER BY o.placed_at DESC, o.id DESC LIMIT 50;

\echo ===== E1b orders fragment medium ('kowal')
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross
FROM ordering_order o
WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('kowal') || '%'
ORDER BY o.placed_at DESC, o.id DESC LIMIT 50;

\echo ===== E1c orders fragment rare ('xq7z')
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross
FROM ordering_order o
WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('xq7z') || '%'
ORDER BY o.placed_at DESC, o.id DESC LIMIT 50;

\echo ===== E1d orders fragment medium, sorted by total ('kowal')
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.email, o.full_name, o.total_gross
FROM ordering_order o
WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('kowal') || '%'
ORDER BY o.total_gross DESC, o.id DESC LIMIT 50;

\echo ===== E2a bounded count fragment frequent ('example')
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o
  WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('example') || '%' LIMIT 10001) bounded;
\echo ===== E2b bounded count fragment medium ('kowal')
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o
  WHERE lower(o.number || E'\x1f' || o.email || E'\x1f' || o.full_name) LIKE '%' || lower('kowal') || '%' LIMIT 10001) bounded;

\echo ===== E3a admin products in a small leaf category
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p
WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :'small_prefix')
       OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id
                  AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :'small_prefix')))
ORDER BY p.name ASC, p.id ASC LIMIT 50;
\echo ===== E3b admin products in the biggest department
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p
WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :'big_prefix')
       OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id
                  AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :'big_prefix')))
ORDER BY p.name ASC, p.id ASC LIMIT 50;
\echo ===== E4 bounded count, small leaf category
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM catalog_product p
  WHERE (p.primary_category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :'small_prefix')
         OR EXISTS (SELECT 1 FROM catalog_product_category pc WHERE pc.product_id = p.id
                    AND pc.category_id IN (SELECT c.id FROM catalog_category c WHERE c.path LIKE :'small_prefix')))
  LIMIT 10001) bounded;

\echo ===== E5 discount codes with active uses
EXPLAIN (ANALYZE, BUFFERS) SELECT c.id, c.code,
       (SELECT count(*) FROM pricing_discount_code_usage u WHERE u.code_id = c.id AND u.released_at IS NULL) AS active_uses
FROM pricing_discount_code c ORDER BY c.created_at DESC, c.id LIMIT 50;

\echo ===== E6a customers fragment frequent ('example')
EXPLAIN (ANALYZE, BUFFERS) SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at
FROM ordering_customer c
WHERE (lower(c.email) LIKE '%' || lower('example') || '%' OR lower(c.full_name) LIKE '%' || lower('example') || '%')
ORDER BY c.last_order_at DESC, c.customer_id LIMIT 50;
\echo ===== E6b customers fragment medium ('nowak')
EXPLAIN (ANALYZE, BUFFERS) SELECT c.customer_id, c.email, c.full_name, c.order_count, c.paid_gross, c.last_order_at
FROM ordering_customer c
WHERE (lower(c.email) LIKE '%' || lower('nowak') || '%' OR lower(c.full_name) LIKE '%' || lower('nowak') || '%')
ORDER BY c.last_order_at DESC, c.customer_id LIMIT 50;

\echo ===== E7 orders status + last day + sort by total
EXPLAIN (ANALYZE, BUFFERS) SELECT o.id, o.number, o.placed_at, o.status, o.total_gross FROM ordering_order o
WHERE o.status = 'delivered' AND o.placed_at >= now() - interval '1 day' AND o.placed_at < now()
ORDER BY o.total_gross DESC, o.id DESC LIMIT 50;

\echo ===== E8 bounded count of a status
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM ordering_order o WHERE o.status = 'delivered' LIMIT 10001) bounded;

\echo ===== E9 active uses of the most used code
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM pricing_discount_code_usage WHERE code_id = :'hot_code' AND released_at IS NULL;

\echo ===== E10 outbox count
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM messenger_messages WHERE queue_name = 'outbox';

\echo ===== E12 sitemap chunk boundaries
EXPLAIN (ANALYZE, BUFFERS) SELECT id FROM (SELECT id, row_number() OVER (ORDER BY id) AS position FROM catalog_product) numbered
WHERE position > 1 AND (position - 1) % 10000 = 0 ORDER BY position;

\echo ===== E13 initialize.sh counts
EXPLAIN (ANALYZE, BUFFERS) SELECT (SELECT count(*) FROM catalog_product), (SELECT count(*) FROM pricing_variant_price),
       (SELECT count(*) FROM inventory_stock_item), (SELECT count(*) FROM ordering_order);

\echo ===== E14a admin products ILIKE 2 chars
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name FROM catalog_product p WHERE p.name ILIKE '%ko%' ORDER BY p.name ASC, p.id ASC LIMIT 50;
\echo ===== E14b admin products ILIKE rare
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name FROM catalog_product p WHERE p.name ILIKE '%xyzw%' ORDER BY p.name ASC, p.id ASC LIMIT 50;
\echo ===== E14c admin products ILIKE common word
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name FROM catalog_product p WHERE p.name ILIKE '%buty%' ORDER BY p.name ASC, p.id ASC LIMIT 50;

\echo ===== E19 out-of-stock products page
EXPLAIN (ANALYZE, BUFFERS) SELECT product_id FROM inventory_stock_item
WHERE physical - reserved = 0 AND product_id IS NOT NULL GROUP BY product_id ORDER BY product_id LIMIT 50;

\echo ===== E22 category tree counts
EXPLAIN (ANALYZE, BUFFERS) SELECT c.id, (SELECT count(*) FROM (
    SELECT 1 FROM catalog_product p WHERE p.primary_category_id = c.id
    UNION ALL SELECT 1 FROM catalog_product_category pc WHERE pc.category_id = c.id LIMIT 1001) t) AS products
FROM catalog_category c;
