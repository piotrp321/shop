-- without images + rare phrase, count of the first page
-- cost variable: hits = 46 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%ravotar%' AND p.images = '[]'::jsonb LIMIT 10001) bounded;
