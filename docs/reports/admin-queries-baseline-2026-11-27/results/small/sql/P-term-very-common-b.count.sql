-- phrase very-common-b 'ka', count of the first page
-- cost variable: hits = 1008 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%ka%' LIMIT 10001) bounded;
