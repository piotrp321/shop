-- phrase type-late 'zupa', count of the first page
-- cost variable: hits = 8584 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%zupa%' LIMIT 10001) bounded;
