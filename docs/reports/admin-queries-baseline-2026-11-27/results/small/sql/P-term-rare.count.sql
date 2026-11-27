-- phrase rare 'ryś', count of the first page
-- cost variable: hits = 20 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%ryś%' LIMIT 10001) bounded;
