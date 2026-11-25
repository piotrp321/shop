\echo '=== P1 no filter, sort name, first page'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P1c bounded count no filter'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM catalog_product p LIMIT 10001) bounded;
\echo '=== P2 sort -updatedAt'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
\echo '=== P3 far page name cursor (Zupa)'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE (p.name, p.id) > ('Zupa liofilizowana Z', CAST('00000000-0000-0000-0000-000000000000' AS uuid)) ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P4 q 2-char frequent "ka"'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%ka%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P4c count "ka"'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%ka%' LIMIT 10001) bounded;
\echo '=== P5 q 2-char no results "qx"'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%qx%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P6 q 3-char frequent "pro"'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%pro%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P6c count "pro"'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%pro%' LIMIT 10001) bounded;
\echo '=== P7 q medium "termos"'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%termos%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P7b q "termos" sort -updatedAt'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%termos%' ORDER BY p.updated_at DESC, p.id DESC LIMIT 26;
\echo '=== P7c count termos'
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%termos%' LIMIT 10001) bounded;
\echo '=== P8 q no results "zzqqxx"'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%zzqqxx%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
\echo '=== P9 q rare full "Havmidek Dystans 200"'
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.name, p.tax_class, p.updated_at FROM catalog_product p WHERE p.name ILIKE '%Havmidek Dystans 200%' ORDER BY p.name ASC, p.id ASC LIMIT 26;
