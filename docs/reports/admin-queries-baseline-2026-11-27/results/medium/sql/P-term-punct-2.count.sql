-- phrase punct-2 '---', count of the first page
-- cost variable: hits = 0 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%---%' LIMIT 10001) bounded;
