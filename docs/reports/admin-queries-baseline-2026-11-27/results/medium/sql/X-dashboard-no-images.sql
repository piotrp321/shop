-- dashboard, products without images (limit 1000)
-- cost variable: bound = 1001 (scales with const)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.images = '[]'::jsonb LIMIT 1001) bounded;
