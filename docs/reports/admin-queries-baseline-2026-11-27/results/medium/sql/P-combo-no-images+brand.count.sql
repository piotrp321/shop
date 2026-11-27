-- without images + brand, count of the first page
-- cost variable: hits = 1239 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.name ILIKE '%rowerowa%' AND p.images = '[]'::jsonb LIMIT 10001) bounded;
