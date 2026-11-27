-- phrase polish-lower 'skała', count of the first page
-- cost variable: hits = 10067 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%skała%' LIMIT 10001) bounded;
