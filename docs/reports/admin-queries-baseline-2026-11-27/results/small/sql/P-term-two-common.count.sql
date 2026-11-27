-- phrase two-common 'er', count of the first page
-- cost variable: hits = 559 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%er%' LIMIT 10001) bounded;
