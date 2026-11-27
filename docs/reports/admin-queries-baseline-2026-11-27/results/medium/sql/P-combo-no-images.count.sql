-- without images, count of the first page
-- cost variable: hits = 20003 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.images = '[]'::jsonb LIMIT 10001) bounded;
