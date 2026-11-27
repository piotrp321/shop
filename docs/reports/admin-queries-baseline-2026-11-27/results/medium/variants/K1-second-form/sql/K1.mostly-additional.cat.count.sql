-- category mostly-additional (6689 products), count
-- cost variable: products in the subtree = 6689 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = ANY('{c57c017b-d6f7-5990-82bf-635533003629}'::uuid[]) UNION ALL SELECT 1 FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id WHERE pc.category_id = ANY('{c57c017b-d6f7-5990-82bf-635533003629}'::uuid[]) AND NOT (p.primary_category_id = ANY('{c57c017b-d6f7-5990-82bf-635533003629}'::uuid[])) LIMIT 10001) bounded;
