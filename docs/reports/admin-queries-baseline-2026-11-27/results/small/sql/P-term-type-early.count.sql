-- phrase type-early 'bagażnik', count of the first page
-- cost variable: hits = 13 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%bagażnik%' LIMIT 10001) bounded;
