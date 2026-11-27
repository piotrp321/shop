-- phrase type-late 'worek', count of the first page
-- cost variable: hits = 12 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%worek%' LIMIT 10001) bounded;
