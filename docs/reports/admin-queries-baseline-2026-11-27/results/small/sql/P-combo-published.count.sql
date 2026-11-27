-- published only, count of the first page
-- cost variable: hits = 2145 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.status = 'published' LIMIT 10001) bounded;
