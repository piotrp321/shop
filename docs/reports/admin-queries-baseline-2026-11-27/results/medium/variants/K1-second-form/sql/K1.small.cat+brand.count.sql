-- category small + common phrase, count
-- cost variable: products in the subtree = 15 (scales with products)
SELECT count(*) FROM (SELECT 1 FROM catalog_product p WHERE p.primary_category_id = ANY('{d947db5c-6f5f-5457-94f2-4b487d81a22e}'::uuid[]) AND p.name ILIKE '%pro%' UNION ALL SELECT 1 FROM catalog_product_category pc JOIN catalog_product p ON p.id = pc.product_id WHERE pc.category_id = ANY('{d947db5c-6f5f-5457-94f2-4b487d81a22e}'::uuid[]) AND NOT (p.primary_category_id = ANY('{d947db5c-6f5f-5457-94f2-4b487d81a22e}'::uuid[])) AND p.name ILIKE '%pro%' LIMIT 10001) bounded;
