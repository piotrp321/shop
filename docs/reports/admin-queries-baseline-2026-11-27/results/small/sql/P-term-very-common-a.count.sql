-- phrase very-common-a 'pro', count of the first page
-- cost variable: hits = 251 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%pro%' LIMIT 10001) bounded;
