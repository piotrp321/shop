-- phrase rare 'ravotar', count of the first page
-- cost variable: hits = 2980 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%ravotar%' LIMIT 10001) bounded;
