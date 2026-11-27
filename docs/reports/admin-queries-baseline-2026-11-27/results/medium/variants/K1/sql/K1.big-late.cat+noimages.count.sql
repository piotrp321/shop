-- category big-late + without images, count
-- cost variable: products in the subtree = 22829 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = ANY('{c65841c4-e5f5-58f5-9451-5e985cfec063}'::uuid[]) AND p.images = '[]'::jsonb UNION ALL SELECT 1 FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id WHERE pc.category_id = ANY('{c65841c4-e5f5-58f5-9451-5e985cfec063}'::uuid[]) AND NOT (p.primary_category_id = ANY('{c65841c4-e5f5-58f5-9451-5e985cfec063}'::uuid[])) AND p.images = '[]'::jsonb LIMIT 10001) bounded;
