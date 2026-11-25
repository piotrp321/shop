\echo '=== P7 warm rerun termos'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%termos%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P10 q "zupa" (late alphabet)'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%zupa%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P11 q "żuraw 900 pro" rare'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%żuraw 900 pro%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P12 q "ravotar" -name'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ravotar%' ORDER BY p.name DESC, p.id DESC LIMIT 26;
\echo '=== P13 q 2-char "zx" sort updatedAt'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%zx%' ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
\echo '=== P14 count 2-char no result qx'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%qx%' LIMIT 10001) bounded;
\echo '=== P15 status=draft sort name'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'draft' ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P16 status=published sort -updatedAt'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.status = 'published' ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
\echo '=== P17 images=none sort name'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.images = '[]'::jsonb ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P17c count images=none'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.images = '[]'::jsonb LIMIT 10001) bounded;
\echo '=== P18 draft + images none + q termos'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%termos%' AND p.status='draft' AND p.images = '[]'::jsonb ORDER BY p.name ASC, p.id ASC LIMIT 26;
